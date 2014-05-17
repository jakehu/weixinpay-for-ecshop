<?php

/**
 * ECSHOP 微信支付插件
 * ============================================================================
 * * 版权所有 2005-2012 上海商派网络科技有限公司，并保留所有权利。
 * 网站地址: http://www.ecshop.com；
 * ----------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在不用于商业目的的前提下对程序代码进行修改和
 * 使用；不允许对程序代码以任何形式任何目的的再发布。
 * ============================================================================
 * $Author: jakehu $
 * $Id: wxpay.php 17217 2014-04-30 06:29:08Z jakehu $
 */

if (!defined('IN_ECS'))
{
    die('Hacking attempt');
}

$payment_lang = ROOT_PATH . 'languages/' .$GLOBALS['_CFG']['lang']. '/payment/wxpay.php';

if (file_exists($payment_lang))
{
    global $_LANG;

    include_once($payment_lang);
}

/* 模块的基本信息 */
if (isset($set_modules) && $set_modules == TRUE)
{
    $i = isset($modules) ? count($modules) : 0;

    /* 代码 */
    $modules[$i]['code']    = basename(__FILE__, '.php');

    /* 描述对应的语言项 */
    $modules[$i]['desc']    = 'wxpay_desc';

    /* 是否支持货到付款 */
    $modules[$i]['is_cod']  = '0';

    /* 是否支持在线支付 */
    $modules[$i]['is_online']  = '1';

    /* 作者 */
    $modules[$i]['author']  = 'jakehu';

    /* 网址 */
    $modules[$i]['website'] = 'http://wx.qq.com';

    /* 版本号 */
    $modules[$i]['version'] = '0.0.1';

	/* 配置信息 */
    $modules[$i]['config']  = array(
        array('name' => 'wxpay_app_id',           'type' => 'text',   'value' => ''),
        array('name' => 'wxpay_app_secret',       'type' => 'text',   'value' => ''),
        array('name' => 'wxpay_partnerid',        'type' => 'text',   'value' => ''),
        array('name' => 'wxpay_partnerkey',       'type' => 'text',   'value' => ''),
        array('name' => 'wxpay_paySignKey',       'type' => 'text',   'value' => '')
    );

    return;
}

/**
 * 类
 */
class wxpay
{
	public $wxpay_app_id		= '';
	public $wxpay_app_secret	= '';
	public $wxpay_partnerid	= '';
	public $wxpay_partnerkey	= '';
	public $wxpay_paySignKey	= '';
	private $_background_notify_url = 'http://'; ///后台支付成功通知url，需要给微信返回success
	private $_pay_success_url = 'http://'; ///支付成功后前台展示给用户的地址

	private $_redis = null;
	
	private function _redis_connect(){
		if(empty($this->_redis)){
			try{
				$this->_redis = new Redis();
				$this->_redis->connect('caiya1',6379);
			}catch(Exception $e){
				return false;
			}
		}
		return $this->_redis;
	}
	private function _redis_set($key, $val, $lifetime=0){
		if($this->_redis_connect()){
			return $this->_redis->set($key,$val,$lifetime);
		}
		return false;
	}
	private function _redis_get($key){
		if($this->_redis_connect()){
			return $this->_redis->get($key);
		}
		return false;
	}

    /**
     * 构造函数
     *
     * @access  public
     * @param
     *
     * @return void
     */
    

    function __construct()
    {
		$payment    = get_payment('wxpay');
		if(isset($payment)){
			$this->wxpay_app_id		=       $payment['wxpay_app_id'];
			$this->wxpay_app_secret	=       $payment['wxpay_app_secret'];
			$this->wxpay_partnerid	=       $payment['wxpay_partnerid'];
			$this->wxpay_partnerkey	=       $payment['wxpay_partnerkey'];
			$this->wxpay_paySignKey	=       $payment['wxpay_paySignKey'];
		}

    }

    /**
     * 生成支付代码
     * @param   array   $order      订单信息
     * @param   array   $payment    支付方式信息
     */
    function get_code($order, $payment)
    {
        if (!defined('EC_CHARSET'))
        {
            $charset = 'utf-8';
        }
        else
        {
            $charset = EC_CHARSET;
        }

		if(!$this->is_show_pay($_SERVER['HTTP_USER_AGENT'])){
			///TODO:显示支付二维码
			$pay_url = 'http://';
			return "<img src=\"\" />";
		}
/////////////////
		$noncestr = uniqid();
		$timestamp = time();
		$parameter = array(
			'appid' => $this->wxpay_app_id,
			'timestamp' => $timestamp, // 13位时间戳
			'noncestr' => $noncestr, // 随机字符串
			'body' => $order['order_sn'], // 商品描述
			'out_trade_no' => $order['order_sn'], // 本站订单号
			'notify_url' => $this->_background_notify_url, // 微信支付成功服务器通知，可自定义
			'spbill_create_ip' => $_SERVER['REMOTE_ADDR'], // 微信用户IP
			'total_fee' => intval($order['order_amount'] * 100), // 支付金额 单位：分
		);

		$parameter = $this->bulidForm($parameter);
		$package = $parameter['package'];
		$paysign = $parameter['paysign'];

////////////////////////////////////////

        
        $button = <<<EOT
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>微信支付</title>
</head>

<body>
<form>
<p>订单号：{$order['order_sn']}</p>
<!--input type="submit" value="微信安全支付" id="getBrandWCPayRequest" /-->
</form>
<script type="text/javascript">
// 当微信内置浏览器完成内部初始化后会触发WeixinJSBridgeReady事件。
// 其实中间部分可以写成一个事件，点击某个按钮触发
document.addEventListener('WeixinJSBridgeReady', function onBridgeReady() {

     WeixinJSBridge.invoke('getBrandWCPayRequest',{
                           "appId" : '{$this->wxpay_app_id}', //公众号名称，由商户传入
                           "timeStamp" : '{$timestamp}', //时间戳
                           "nonceStr" : '{$noncestr}', //随机串
                           "package" : '{$package}',//扩展包
                           "signType" : 'SHA1', //微信签名方式:1.sha1
                           "paySign" : '{$paysign}' //微信签名
     },function(res){
        if(res.err_msg == "get_brand_wcpay_request:ok" ) {
            window.location.href = '{$this->_pay_success_url}';               
        } else {
            // 这里是取消支付或者其他意外情况，可以弹出错误信息或做其他操作
            alert('未知原因支付失败，请改用其他支付方式');
        }
     }); 

}, false)
</script>
<!-- 下面为必需js文件 -->
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.5/jquery.min.js"></script>
<script type="text/javascript" src="http://res.mail.qq.com/mmr/static/lib/js/lazyloadv3.js"></script>
</body>
</html>
EOT;
        return $button;
    }
    
    function get_requestid($requestId){
		$pos = strpos($requestId, '-');
		if($pos>2){
			$requestId = substr($requestId, $pos+1);
		}
		return $requestId;
	}

    /**
     * 响应操作
     */
    function respond()
    {
        $payment    = get_payment('wxpay');

        /*取返回参数*/
		$fields = 'bank_billno,bank_type,discount,fee_type,input_charset,notify_id,out_trade_no,partner,product_fee'
				 .',sign_type,time_end,total_fee,trade_mode,trade_state,transaction_id,transport_fee';
		$arr = null;
		foreach(explode(',',$fields) as $val){
			if(isset($_REQUEST[$val])){
				$arr[$val] = trim($_REQUEST[$val]);
			}
		}
        $order_sn   = $arr['out_trade_no'];

        $log_id = get_order_id_by_sn($order_sn);

        /* 如果trade_state大于0则表示支付失败 */
        if ($arr['trade_state'] > 0)
        {
            return false;
        }

        /* 检查支付的金额是否相符 */
        if (!check_money($log_id, $arr['total_fee'] / 100))
        {
            return false;
        }

		$sign = $_REQUEST['sign'];
		$sign_md5 = $this->create_sign($arr);
        if ($sign_md5 != $sign)
        {
            return false;
        }
        else
        {
            /* 改变订单状态 */
            order_paid($log_id);
            return true;
        }
    }

	
	public function open($api_url){
		$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $ret = curl_exec($ch);
        $error = curl_error($ch);

        if($error){
            return false;
        }

        $json = json_decode($ret, TRUE);

        return $json;
    }
    
    
    public function post($api_url,$data){
	    $context = array('http' => array('method' => "POST", 'header' => "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 6.0; en-US) \r\n Accept: */*", 'content' => $data));
	    $stream_context = stream_context_create($context);
	    $ret = @file_get_contents($api_url, FALSE, $stream_context);
	    return json_decode($ret, true);
    }
    
    public function code_token($code, $cache = true){
		if(empty($code)){
			return false;
		}
		$code_token_key = 'code_token_key';
		if(empty($_COOKIE[$code_token_key])){
			$_COOKIE[$code_token_key] = md5(time().$_SERVER['REMOTE_ADDR']);
			setcookie($code_token_key,$key,time()+86400,'/');
		}
		$key = $_COOKIE[$code_token_key];
		if(!$at=$this->_redis_get($key) && $cache){
			$at = $this->open("https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->wxpay_app_id}&secret={$this->wxpay_app_secret}&code={$code}&grant_type=authorization_code");
			$this->_redis_set($code_token_key.$key,$at,$at['expires_in']);
		}
		return $at;
	}
    
    /**
     * 获取access token
     * @return array
     */
    public function access_token($cache=true){
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->wxpay_app_id}&secret={$this->wxpay_app_secret}";
        $access_token_key = '_wx_access_token_cy_20140423_';
        $access_token = $this->_redis_get($access_token_key);
        if ( !empty($access_token) && $cache ){
        	return $access_token;
    	}
        try{
            $ret = $this->open($url);
			$this->_redis_set($access_token_key,$ret['access_token'],$ret['expires_in']);
            return $ret['access_token'];
        }catch(Exception $e){
            return '';
        }
        
    }	    
    /**
 	* 除去数组中的空值和签名参数
 	* @param $para 签名参数组
 	* return 去掉空值与签名参数后的新签名参数组
 	*/
	
	private	function parafilter($para) {

		foreach ($para as $key => $val ) {
			if($key == "sign_method" || $key == "sign" ||$val === ""){
				unset($para[$key]);
			}
		}
		return $para;
	}
	
	/**
	 * 对数组排序
 	* @param $para 排序前的数组
 	* return 排序后的数组
 	*/
	private function argsort($para) {
		ksort($para);
		reset($para);
		return $para;
	}
	
	/**
	 * 把数组所有元素，按照“参数=参数值”的模式用“&”字符拼接成字符串
	 * @param $para 需要拼接的数组
	 * return 拼接完成以后的字符串
	 */
	private function createlinkstring($para) {
		$arg  = "";
		foreach ($para as $key => $val ) {
			$arg.=strtolower($key)."=".$val."&";
		}
		//去掉最后一个&字符
		$arg = substr($arg,0,count($arg)-2);
		
		//如果存在转义字符，那么去掉转义
		if(get_magic_quotes_gpc()){$arg = stripslashes($arg);}
		
		return $arg;	
		/*
		return http_build_query($para);
		*/

	}

    /**
     * 创建app_signature
     * @return string
     */
    private function create_app_signature( $arr ){
        $para = $this->parafilter($arr);
		$para = $this->argsort($para); 
		$signValue = sha1($this->createlinkstring($para));
        return $signValue;
    }
    
    
    /**
     * 创建sign
     * @return string
     */
    private function create_sign( $arr ){
		$partnerKey = $this->wxpay_partnerkey;
        $para = $this->parafilter($arr);
		$para = $this->argsort($para);
		$signValue = $this->createlinkstring($para);
		$signValue = $signValue."&key=".$partnerKey;
		$signValue = strtoupper(md5($signValue));	
        return $signValue;
    }
    /**
     * 标记客户的投诉处理状态
     * @return bool
     */
    public function payfeedback_update($openid,$feedbackid){
    	 $url = "https://api.weixin.qq.com/payfeedback/update?access_token=".$this->access_token()."&openid=".$openid."&feedbackid=".$feedbackid;
         $ret = $this->open($url);
         if ( in_array($ret['errcode'],array(40001,40002,42001)) ){
         	$this->access_token(false);
         	return $this->payfeedback_update($openid,$feedbackid);
         }
         return $ret;
    }
    
    /**
     * 发货通知
     *  
     * openid					购买用户的 OpenId，这个已经放在最终支付结果通知的 PostData 里了 
     * transid					交易单号
     * out_trade_no				第三方订单号
     * deliver_timestamp		发货时间戳
     * deliver_status			发货状态	1:成功 0:失败
     * deliver_msg				发货状态信息	
    
     *
     */
    public function delivernotify( $openid,$transid,$out_trade_no,$deliver_status=1,$deliver_msg='ok'){
    	$post = array();
    	$post['appid'] = $this->wxpay_app_id;
    	$post['appkey'] = $this->wxpay_app_key;
    	$post['openid'] = $openid;
    	$post['transid'] = $transid;
    	$post['out_trade_no'] = $out_trade_no;
    	$post['deliver_timestamp'] = time();
    	$post['deliver_status'] = $deliver_status;
    	$post['deliver_msg'] = $deliver_msg;
    	
    	$post['app_signature'] = $this->create_app_signature($post);
    	$post['sign_method'] = "SHA1";
    	
    	$data = json_encode($post);
    	
    	$url = 'https://api.weixin.qq.com/pay/delivernotify?access_token=' . $this->access_token();
	    $ret = $this->post($url,$data);
	    if ( in_array($ret['errcode'],array(40001,40002,42001)) ){
         	$this->access_token(false);
         	return $this->delivernotify($openid,$transid,$out_trade_no,$deliver_status,$deliver_msg);
        }
	    return $ret;
    }
    
    
     /**
     * 订单查询
     * @return array
     */
    public function order_query($out_trade_no){
    	$post = array();
    	$post['appid'] = $this->wxpay_app_id;
    	$sign = $this->create_sign(array('out_trade_no' => $out_trade_no , 'partner' => $this->wxpay_partnerid ));
    	$post['package'] = "out_trade_no=$out_trade_no&partner=".$this->wxpay_partnerid."&sign=$sign";
    	$post['timestamp'] = time();
    	
    	$post['app_signature'] = $this->create_app_signature(array('appid' =>$this->wxpay_app_id , 'appkey' => $this->wxpay_app_key , 'package' => $post['package'] , 'timestamp' => $post['timestamp'] ));
    	$post['sign_method'] = "SHA1";
    	
    	$data = json_encode($post);
    	
    	$url = 'https://api.weixin.qq.com/pay/orderquery?access_token=' . $this->access_token();
	    $ret = $this->post($url,$data);
	    if ( in_array($ret['errcode'],array(40001,40002,42001)) ){
         	$this->access_token(false);
         	return $this->order_query($out_trade_no);
        }
	    return $ret;
    }
    
    
    /**
     * 构建支付请求数组
     * @return array
     */
    public function bulidForm($parameter){
		$paySignKey = $this->wxpay_paySignKey;
		$app_id = $this->wxpay_app_id;
    	$parameter['package'] = $this->buildPackage($parameter); // 生成订单package
       	$paySignArray = array('appid' => $app_id, 'appkey' => $paySignKey ,'noncestr' => $parameter['noncestr'], 'package' => $parameter['package'], 'timestamp' => $parameter['timestamp']);
       	$parameter['paysign'] = $this->create_app_signature($paySignArray);
       	return $parameter;
    }
    
    
    /**
     * 构建支付请求包
     * @return string
     */
    public function buildPackage($parameter){
    	$filter = array('bank_type', 'body', 'attach', 'partner', 'out_trade_no', 'total_fee', 'fee_type', 'notify_url','spbill_create_ip', 'time_start', 'time_expire', 'transport_fee', 'product_fee', 'goods_tag', 'input_charset');
        $base = array(
            'bank_type' => 'WX',
            'fee_type' => '1',
            'input_charset' => 'UTF-8',
            'partner' => $this->wxpay_partnerid
        );
        $parameter = array_merge($parameter, $base);
        $array = array();
        foreach ($parameter as $k => $v) {
            if (in_array($k, $filter)) {
                $array[$k] = $v;
            }
        }
        ksort($array);
        $signPars = '';
        reset($array);
        foreach ($array as $k => $v) {
            $signPars .= strtolower($k) . "=" . $v . "&";
        }
        $sign = strtoupper(md5($signPars . 'key=' . $this->wxpay_partnerkey));
        $signPars = '';
        reset($array);
        foreach ($array as $k => $v) {
            $signPars .= strtolower($k) . "=" . urlencode($v) . "&";
        }        
        
        return $signPars . 'sign=' . $sign;
    }
    
    
    /**
     * 从xml中获取数组
     * @return array
     */
    public function getXmlArray() {
		$postStr = @file_get_contents('php://input');
		if ($postStr) {
			$postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
            if (! is_object($postObj)) {
                return false;
            }
            $array = json_decode(json_encode($postObj), true); // xml对象转数组
            return array_change_key_case($array, CASE_LOWER); // 所有键小写
        } else {
            return false;
        }        
    }
    
    
    /**
	 * 验证服务器通知
	 * @param array $data
	 * @return array
	 */
	public function verifyNotify($post,$sign) {
        $para = $this->parafilter($post);
		$para = $this->argsort($para); 
		$signValue = $this->createlinkstring($para);
		$signValue = $signValue."&key=".$this->wxpay_partnerkey;
		$signValue = strtoupper(md5($signValue));
		if ( $sign == $signValue ){
			return true;	
		}else{
			return false;
		}
		
	}
	
	
	 /**
	 * 是否支持微信支付
	 * @return bool
	 */
	public function is_show_pay($agent) {
		$ag1  = strstr($agent,"MicroMessenger");
		$ag2 = explode("/",$ag1);
		$ver = floatval($ag2[1]);
		if ( $ver < 5.0 || empty($aid) ){
			return false;
    	}else{
    		return true;
    	}
	}   
}

?>