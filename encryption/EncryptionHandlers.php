<?php
require_once KS3_API_PATH.DIRECTORY_SEPARATOR."encryption".DIRECTORY_SEPARATOR."EncryptionUtil.php";
require_once KS3_API_PATH.DIRECTORY_SEPARATOR."encryption".DIRECTORY_SEPARATOR."EncryptionCallBack.php";
require_once KS3_API_PATH.DIRECTORY_SEPARATOR."exceptions".DIRECTORY_SEPARATOR."Exceptions.php";
interface EncryptionHandler{
	public function putObjectByContentSecurely($args=array());
	public function putObjectByFileSecurely($args=array());
	public function getObjectSecurely($args=array());
	public function initMultipartUploadSecurely($args=array());
	public function uploadPartSecurely($args=array());
	public function abortMultipartUploadSecurely($args=array());
	public function completeMultipartUploadSecurely($args=array());
}
class EncryptionEO implements EncryptionHandler{
	private $encryptionMaterials = NULL;
	private $ks3client = NULL;
	public function __construct($ks3client,$encryptionMaterials){
		$this->encryptionMaterials = $encryptionMaterials;
		$this->ks3client = $ks3client;
	}
	public function putObjectByContentSecurely($args=array()){
		$sek = EncryptionUtil::genereateOnceUsedKey();
		$encryptedSek = EncryptionUtil::encodeCek($this->encryptionMaterials,$sek);
		$content = $args["Content"];
		if(empty($content))
			throw new Ks3ClientException("please specifie Content in request args");
		$metaContentLength = EncryptionUtil::metaTextLength($args);
		$plainTextLength = strlen($content);
		if($metaContentLength > 0 && $metaContentLength < $plainTextLength){
			$plainTextLength = $metaContentLength;
		}
		if($plainTextLength > 0)
			$args["UserMeta"]["x-kss-meta-x-kss-unencrypted-content-length"] = $plainTextLength;
		else
			throw new Ks3ClientException("unexpected content length ".$plainTextLength);

		$content =  substr($content, 0,$plainTextLength);


		$td = mcrypt_module_open(MCRYPT_RIJNDAEL_128,'',MCRYPT_MODE_CBC,'');
		$iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td),MCRYPT_RAND);
		mcrypt_generic_init($td,$sek,$iv);
		//对content进行pkcs5填充
		$content = EncryptionUtil::PKCS5Padding($content,mcrypt_get_block_size(MCRYPT_RIJNDAEL_128,MCRYPT_MODE_CBC));
		$encrypted = mcrypt_generic($td,$content);
		mcrypt_generic_deinit($td);

		$args["ObjectMeta"]["Content-Length"] = strlen($encrypted);
		$args["Content"] = $encrypted; 

		$args = EncryptionUtil::updateContentMD5Header($args);

		$args["UserMeta"]["x-kss-meta-x-kss-key"] = base64_encode($encryptedSek);
		$args["UserMeta"]["x-kss-meta-x-kss-iv"] = base64_encode($iv);

		return $this->ks3client->putObjectByContent($args);
	}
	public function putObjectByFileSecurely($args=array()){
		$sek = EncryptionUtil::genereateOnceUsedKey();
		$encryptedSek = EncryptionUtil::encodeCek($this->encryptionMaterials,$sek);
		if(!isset($args["Content"])||!is_array($args["Content"])
			||!isset($args["Content"]["content"])
			||empty($args["Content"]["content"]))
			throw new Ks3ClientException("please specifie file content in request args");
		$content = $args["Content"];
		$plainTextLength = EncryptionUtil::plainTextLength($args);
		if($plainTextLength <= 0){
			throw new Ks3ClientException("get content length failed ,unexpected content length ".$plainTextLength);
		}
		$td = mcrypt_module_open(MCRYPT_RIJNDAEL_128,'',MCRYPT_MODE_CBC,'');
		$iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td),MCRYPT_RAND);

		$args = EncryptionUtil::updateContentMD5Header($args);
		$encryptedLength = EncryptionUtil::getPKCS5EncrypedLength($plainTextLength,mcrypt_get_block_size(MCRYPT_RIJNDAEL_128,MCRYPT_MODE_CBC));

		$args["ObjectMeta"]["Content-Length"] = $encryptedLength;
		$args["UserMeta"]["x-kss-meta-x-kss-unencrypted-content-length"] = $plainTextLength;
		$args["UserMeta"]["x-kss-meta-x-kss-key"] = base64_encode($encryptedSek);
		$args["UserMeta"]["x-kss-meta-x-kss-iv"] = base64_encode($iv);

		$readCallBack = new AESCBCStreamReadCallBack();
		$readCallBack->iv = $iv;
		$readCallBack->cek = $sek;
		$readCallBack->contentLength = $plainTextLength;
		$args["readCallBack"] = $readCallBack;

		return $this->ks3client->putObjectByFile($args);
	}
	public function getObjectSecurely($args=array()){
		$meta = $this->ks3client->getObjectMeta($args);
		if(isset($meta["UserMeta"]["x-kss-meta-x-kss-key"])&&isset($meta["UserMeta"]["x-kss-meta-x-kss-iv"])){
			$encrypted = TRUE;
		}else{
			$encrypted = FALSE;
		}
		if($encrypted)
		{
			$iv = base64_decode($meta["UserMeta"]["x-kss-meta-x-kss-iv"]);
			$cekEncrypted = base64_decode($meta["UserMeta"]["x-kss-meta-x-kss-key"]);

			$cek = EncryptionUtil::decodeCek($this->encryptionMaterials,$cekEncrypted);

			$writeCallBack = new AESCBCStreamWriteCallBack();
			$writeCallBack->iv=$iv;
			$writeCallBack->cek=$cek;
			$writeCallBack->contentLength = $meta["ObjectMeta"]["Content-Length"];
			$args["writeCallBack"] = $writeCallBack;
		}
		return $this->ks3client->getObject($args);
	}
	public function initMultipartUploadSecurely($args=array()){
		$sek = EncryptionUtil::genereateOnceUsedKey();
		$encryptedSek = EncryptionUtil::encodeCek($this->encryptionMaterials,$sek);
		$td = mcrypt_module_open(MCRYPT_RIJNDAEL_128,'',MCRYPT_MODE_CBC,'');
		$iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td),MCRYPT_RAND);
		
		$args["UserMeta"]["x-kss-meta-x-kss-key"] = base64_encode($encryptedSek);
		$args["UserMeta"]["x-kss-meta-x-kss-iv"] = base64_encode($iv);

		$initResult = $this->ks3client->initMultipartUpload($args);

		EncryptionUtil::initMultipartUploadContext($initResult,$iv,$sek);

		return $initResult;
	}
	public function uploadPartSecurely($args=array()){
		$uploadId = $args["Options"]["uploadId"];
		$isLastPart = FALSE;
		$blocksize = mcrypt_get_block_size(MCRYPT_RIJNDAEL_128,MCRYPT_MODE_CBC);
		if(isset($args["LastPart"]))
			$isLastPart = $args["LastPart"];
		$exists = EncryptionUtil::multipartUploadContextExists($uploadId);
		if(!$exists){
			throw new Ks3ClientException("no such upload in cache/encryption/");
		}
		$context = EncryptionUtil::getMultipartUploadContext($uploadId);
		if($context["lastPart"]){
			throw new Ks3ClientException("this upload with uploadId ".$uploadId," has been upload last part");
		}
		$plainTextLength = EncryptionUtil::plainTextLength($args);
		if($plainTextLength <= 0){
			throw new Ks3ClientException("get content length failed ,unexpected content length ".$plainTextLength);
		}
		if(!$isLastPart){
			if($plainTextLength % $blocksize != 0)
				throw new Ks3ClientException("Invalid part size,part size (".$plainTextLength.") must be multiples of the block size ".$blocksize);
		}else{
			$args["ObjectMeta"]["Content-Length"] = $plainTextLength + ($blocksize - $plainTextLength%$blocksize);
		}
		$readCallBack = new AESCBCStreamReadCallBack();
		$readCallBack->iv = base64_decode($context["nextIv"]);
		$readCallBack->cek = base64_decode($context["cek"]);
		$readCallBack->contentLength = $plainTextLength;
		$readCallBack->mutipartUpload = TRUE;
		$readCallBack->isLastPart = $isLastPart;
		$args["readCallBack"] = $readCallBack;

		$upResult = $this->ks3client->uploadPart($args);
		EncryptionUtil::updateMultipartUploadContext($uploadId,$readCallBack->iv,$isLastPart);
		return $upResult;
	}
	public function abortMultipartUploadSecurely($args=array()){
		$uploadId = $args["Options"]["uploadId"];
		EncryptionUtil::deleteMultipartUploadContext($uploadId);
		return $this->ks3client->abortMultipartUpload($args);
	}
	public function completeMultipartUploadSecurely($args=array()){
		$uploadId = $args["Options"]["uploadId"];
		$exists = EncryptionUtil::multipartUploadContextExists($uploadId);
		if(!$exists){
			throw new Ks3ClientException("no such upload in cache/encryption/");
		}
		$context = EncryptionUtil::getMultipartUploadContext($uploadId);
		if(!$context["lastPart"]){
			throw new Ks3ClientException("Unable to complete an encrypted multipart upload without being told which part was the last. when upload part you can add item in args like args[\"LastPart\"]=TRUE");
		}
		$result = $this->ks3client->completeMultipartUpload($args);
		EncryptionUtil::deleteMultipartUploadContext($uploadId);
		return $result;
	}
}
?>