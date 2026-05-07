<?php
namespace Mobile\Controller;

class LevertadeController extends MobileController
{
	protected function _initialize()
	{
		parent::_initialize();	$allow_action=array("index","trans","update_levermoney","UpOrderHandle","tradlist","clearorder","closeorder","getprice","confimclose");
		if(!in_array(ACTION_NAME,$allow_action)){
			$this->error(L("非法操作"));
		}
	}
	public function tradlist(){
	    $uid = userid();
	    if($uid <= 0){
	        $this->redirect('Login/index');
	    }
	    //委托订单
        $list = M("leverorder")->where(array('uid'=>$uid,'status'=>1))->order("id desc")->field("id,direction,symbol,wt_price,num,ggan,wttime")->select();
        $this->assign('list',$list);
        
        //持仓订单
        $alllist = M("leverorder")->where(array('uid'=>$uid,'status'=>2))->order("id desc")->select();
        $this->assign('alllist',$alllist);
        
        //成交订单
        $finishlist = M("leverorder")->where("uid = {$uid} and (status = 3 or status = 3)")->order("id desc")->select();
        $this->assign('finishlist',$finishlist);
        
	    $this->display();
	}
	public function closeorder(){
	    $uid = userid();
	    if($uid <= 0){
	        $this->redirect('Login/index');
	    }
	    if($_POST){
	        $id = trim(I('post.id'));
	        if($id == '' || $id <=  0){
	            $this->ajaxReturn(['code'=>2,'info'=>L('缺少重要参数')]);exit();
	        }
	        $oinfo = M("leverorder")->where(array('id'=>$id,'status'=>2))->find();
	        if(empty($oinfo)){
	            $this->ajaxReturn(['code'=>2,'info'=>L('订单不存在')]);exit();
	        }
	        
	        $uinfo = M("user")->where(array('id'=>$uid))->field("id,username")->find();
	        
	        $lowercoin = $oinfo['coinname'];
	        $url = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$lowercoin;
            $result = $this->getprice($url);
            $pdata = $result['data'][0];
            $close = $pdata['close'];//现价
	        //收益计算
	        //例：BTC/USDT永续合约开多仓100张，成交价格为10000 USDT，以11000 USDT价格平仓，
            //则：平仓盈亏 = 收益（平仓） =（ 11000 – 10000 ）* 100 * 0.001 = 100 USDT。
            $direction = $oinfo['direction'];
	        if($direction == 1){ //做多
	            $profit = sprintf("%.2f",(($close - $oinfo['price']) * 100 * 0.001 * $oinfo['ggan']));
	            
	        }elseif($direction == 2){ //做空
	            $profit = sprintf("%.2f",(($oinfo['price'] - $close) * 100 * 0.001 * $oinfo['ggan']));
	        }
            if($profit >= 0){
                $yk_status = 1;
            }else{
                $yk_status = 2;
            }
            
            $tprofit = abs($profit);
            $data['pc_price'] = $close;
            $data['status'] = 3;
            $data['yk_status'] = $yk_status;
            $data['ylmoney'] = $tprofit;
            $data['endtime'] = date("Y-m-d H:i:s",time());
            $result = M("leverorder")->where(array('id'=>$id,'status'=>2))->save($data);
            if($result){
                $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
                if($profit >= 0){
                    M("user_coin")->where(array('userid'=>$uid))->setInc('usdt',$tprofit);
                    $ubill['uid'] = $uid;
		            $ubill['username'] = $uinfo['username'];
		            $ubill['num'] = $tprofit;
		            $ubill['coinname'] = "usdt";
		            $ubill['afternum'] = $minfo['usdt'] + $tprofit;
		            $ubill['type'] = 20;
		            $ubill['addtime'] = date("Y-m-d H:i:s",time());
		            $ubill['st'] = 1;
		            $ubill['remark'] = L('合约交易手动平仓盈利收益');
	                $ubillre = M("bill")->add($ubill);

                }else{
                    if($minfo['usdt'] >= $tprofit){
                        M("user_coin")->where(array('userid'=>$uid))->setDec('usdt',$tprofit);
                        $ubill['uid'] = $uid;
		                $ubill['username'] = $uinfo['username'];
		                $ubill['num'] = $tprofit;
		                $ubill['coinname'] = "usdt";
		                $ubill['afternum'] = $minfo['usdt'] - $tprofit;
		                $ubill['type'] = 20;
		                $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                $ubill['st'] = 2;
		                $ubill['remark'] = L('合约交易手动平仓亏损');
	                    $ubillre = M("bill")->add($ubill);
                    }else{
                        //如果亏损金额大于账户余额，账户余额全扣完，剩下的部分扣保证金
                        $tpro_a = $minfo['usdt'];
                        $tpro_b = $tprofit - $minfo['usdt'];
                        M("user_coin")->where(array('userid'=>$uid))->setDec('usdt',$tpro_a);
                        $ubill['uid'] = $uid;
		                $ubill['username'] = $uinfo['username'];
		                $ubill['num'] = $tpro_a;
		                $ubill['coinname'] = "usdt";
		                $ubill['afternum'] = $minfo['usdt'] - $tpro_a;
		                $ubill['type'] = 20;
		                $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                $ubill['st'] = 2;
		                $ubill['remark'] = L('合约交易手动平仓亏损');
	                    $ubillre = M("bill")->add($ubill);
                        $levermoney_info = M("levermoney")->where(array('uid'=>$uid))->find();
                        if($levermoney_info['money'] >= $tpro_b){
                            M("levermoney")->where(array('uid'=>$uid))->setDec("money",$tpro_b);
                        }else{
                            M("levermoney")->where(array('uid'=>$uid))->save(array('money',0));
                        }
                    }
                }
                $this->ajaxReturn(['code'=>1,'info'=>L('操作成功')]);exit();
            }else{
                $this->ajaxReturn(['code'=>2,'info'=>L('操作失败')]);exit();
            }
	    }else{
	        $this->ajaxReturn(['code'=>2,'info'=>L('非法操作')]);exit();
	    }
	}
	
	public function confimclose(){
	    $uid = userid();
	    if($uid <= 0){
	        $this->redirect('Login/index');
	    }
	    if($_POST){
	        $id = trim(I('post.id'));
	        if($id == '' || $id <=  0){
	            $this->ajaxReturn(['code'=>2,'info'=>L('缺少重要参数')]);exit();
	        }
	        $oinfo = M("leverorder")->where(array('id'=>$id,'status'=>2))->find();
	        if(empty($oinfo)){
	            $this->ajaxReturn(['code'=>2,'info'=>L('订单不存在')]);exit();
	        }
	        $lowercoin = $oinfo['coinname'];
	        $url = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$lowercoin;
            $result = $this->getprice($url);
            $pdata = $result['data'][0];
            $close = $pdata['close'];//现价
	        //收益计算
	        //例：BTC/USDT永续合约开多仓100张，成交价格为10000 USDT，以11000 USDT价格平仓，
            //则：平仓盈亏 = 收益（平仓） =（ 11000 – 10000 ）* 100 * 0.001 = 100 USDT。
            $direction = $oinfo['direction'];
	        if($direction == 1){ //做多
	            $profit = sprintf("%.2f",(($close - $oinfo['price']) * 100 * 0.001 * $oinfo['ggan']));
	        }elseif($direction == 2){ //做空
	            $profit = sprintf("%.2f",(($oinfo['price'] - $close) * 100 * 0.001 * $oinfo['ggan']));
	        }
            
            if($profit >= 0){
                $html = '<span style="font-size:18px;color:#0ecb81;">'.L("现在结算预计盈利").':'.$profit.'USDT，'.L("确定要结算吗？").'</span>';
                $htmls = '<span class="css-46eref">'.L("盈利").':&nbsp'.$profit.'&nbsp;USDT</span>';
            }else{
                $html = '<span style="font-size:18px;color:#f5465c;">'.L("现在结算预计亏损").':'.$profit.'USDT，'.L("确定要结算吗？").'</span>';
                $htmls = '<span class="css-46erefhonng">'.L("亏损").':&nbsp'.$profit.'&nbsp;USDT</span>';
            }
            
            $data['txt'] = $html;
            $data['txts'] = $htmls;
            $data['close'] = $close;
            $data['id'] = $id;
            $data['code'] = 1;
            $this->ajaxReturn($data);exit();
	    }
	}
	public function clearorder(){
	   $uid = userid();
	    if($uid <= 0){
	        $this->redirect('Login/index');
	    }
	    if($_POST){
	        $id = trim(I('post.id'));
	        if($id == '' || $id <=  0){
	            $this->ajaxReturn(['code'=>2,'info'=>L('缺少重要参数')]);exit();
	        }
	        $oinfo = M("leverorder")->where(array('id'=>$id))->find();
	        if(empty($oinfo)){
	            $this->ajaxReturn(['code'=>2,'info'=>L('订单不存在')]);exit();
	        }
	        if($oinfo['status'] != 1){
	            $this->ajaxReturn(['code'=>2,'info'=>L('该合约不能撤消')]);exit();
	        }
	        $result = M("leverorder")->where(array('id'=>$id))->delete();
	        if($result){
	            $this->ajaxReturn(['code'=>1,'info'=>L('撤消成功')]);exit();
	        }else{
	            $this->ajaxReturn(['code'=>2,'info'=>L('撤消失败')]);exit();
	        }
	    }else{
	        $this->ajaxReturn(['code'=>2,'info'=>L('非法操作')]);exit();
	    }
	}
	
	public function UpOrderHandle(){
	    $uid = userid();
	    if($uid <= 0){
	        $this->redirect('Login/index');
	    }
	    if($_POST){
	        
	        $sysinfo = M("leversetting")->where(array('id'=>1))->find();
            if($sysinfo['lever_kg'] != 0){
                $this->ajaxReturn(['code'=>0,'info'=>L('暂停交易')]);
            }
	        $lever_fee = $sysinfo['lever_fee'];//费率
	        $uinfo = M("user")->where(array('id'=>$uid))->field('id,username')->find();
	        $direction = trim(I('post.direction')); //交易方向1做多2做空
	        $type = trim(I('post.type')); //1限价委托2市价委托
	        $symbolstr = trim(I('post.symbol')); //交易对
	        $mprice = trim(I('post.mprice')); //建仓或委托单价
	        $ggan = trim(I('post.ggan')); //杠杆倍数
	        $zyprice = trim(I('post.zyprice')); //止盈价格
	        $zsprice = trim(I('post.zsprice')); //止损价格
	        $num = trim(I('post.num')); //交易手数
	        $arr = explode("/",$symbolstr);
	        $symbol = strtolower($arr[0].$arr[1]);//交易对
            
            $data['orderid'] = date("YmdHis",time()).time().$uid;
            $data['uid'] = $uid;
	        $data['username'] = $uinfo['username'];
	        $data['direction'] = $direction;
	        $data['coinname'] = $symbol;
	        $data['symbol'] = $symbolstr;
	        
	        if($type == 1){
	            $data['price'] = '';
	            $data['wt_price'] = $mprice;
	            $data['wttime'] = date('Y-m-d H:i:s',time());
	            $data['addtime'] = '';
	            $data['status'] = 1;
	            $data['fee'] = '';
	        }elseif($type == 2){
	            $data['price'] = $mprice;
	            $data['wt_price'] = $mprice;
	            $data['wttime'] = date('Y-m-d H:i:s',time());
	            $data['addtime'] = date('Y-m-d H:i:s',time());
	            //手续费计算(开仓价格 * 开仓数量 * 手续费率) ,开仓时扣除手续费
	            $data['fee'] = $fee =  sprintf("%.2f",($mprice * $num * $lever_fee / 100));
	            $data['status'] = 2;
	            $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	            if($minfo['usdt'] < $fee){
	                $this->ajaxReturn(['code'=>2,'info'=>L('账户余额不足')]);exit();
	            }
	        }
	        
	        $data['type'] = $type;
	        $data['num'] = $num;
	        $data['ggan'] = $ggan;
	        $data['yk_status'] = '';
	        $data['ylmoney'] = '';
	        $data['endtime'] = '';

	        $result = M("leverorder")->add($data);
	        if($result){
	            if($type == 2){
	                //扣除手续费
	                $decre = M("user_coin")->where(array('userid'=>$uid))->setDec("usdt",$fee);
	                $ubill['uid'] = $uid;
		            $ubill['username'] = $uinfo['username'];
		            $ubill['num'] = $fee;
		            $ubill['coinname'] = "usdt";
		            $ubill['afternum'] = $minfo['usdt'] - $fee;
		            $ubill['type'] = 19;
		            $ubill['addtime'] = date("Y-m-d H:i:s",time());
		            $ubill['st'] = 2;
		            $ubill['remark'] = L('合约交易开仓手续费');
	                $ubillre = M("bill")->add($ubill);
	            }
	            $this->ajaxReturn(['code'=>1,'info'=>L('订单创建成功')]);exit();
	        }else{
	            $this->ajaxReturn(['code'=>2,'info'=>L('订单创建失败')]);exit();
	        }
	        $this->ajaxReturn(['code'=>2,'info'=>L('未开放')]);exit();
	    }else{
	        $this->ajaxReturn(['code'=>2,'info'=>L('非法操作')]);exit();
	    }
	}
	
	//币币交易详情页面
	public function index(){
	    $uid = userid();
	    if($uid <= 0){
	        $this->redirect('Login/index');
	    }
	    $symbol = trim(I('get.symbol'));
	    if($symbol == ''){
	        $symbol = "BTC";
	    }
	    $coin = strtolower($symbol);
	    $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	    $usdt_blance = $minfo['usdt'];
	    $coin_blance = $minfo[$coin];
	    $this->assign('usdt_blance',$usdt_blance);
	    $this->assign('coin_blance',$coin_blance);
	    $this->assign('uid',$uid);
	    $coinname = $symbol."/"."USDT";
	    $this->assign('coinname',$coinname);
        $this->assign('symbol',$symbol);
        
        $sysinfo = M("leversetting")->where(array('id'=>1))->find();
        $this->assign('sysinfo',$sysinfo);
        
        $lmoneyinfo = M("levermoney")->where(array('uid'=>$uid))->find();
        
        if(empty($lmoneyinfo)){
            $this->update_levermoney($uid);
            $levermoney = 0;
        }else{
            $levermoney = $lmoneyinfo['money'];
        }
        
        $this->assign('levermoney',$levermoney);
        
        
        //委托订单
        $wtlist = M("leverorder")->where(array('uid'=>$uid,'status'=>1))->order("id desc")->field("id,direction,symbol,wt_price,num,ggan,wttime")->select();
        $this->assign('wtlist',$wtlist);
        
        //持仓订单
        $cclist = M("leverorder")->where(array('uid'=>$uid,'status'=>2))->order("id desc")->select();
        $this->assign('cclist',$cclist);
        
        
	    $this->display();
	}
	
	public function update_levermoney($uid){
	    
	    $uinfo = M("user")->where(array('id'=>$uid))->field("id,username")->find();
	    $data['uid'] = $uid;
	    $data['username'] = $uinfo['username'];
	    $data['money'] = 0;
	    M("levermoney")->add($data);
	    
	}
	
	//合约K线
	public function trans(){
	   $uid = userid();
	   $this->assign('uid',$uid);
	   $sytx  = trim(I('get.sytx'));
	   $txarr = explode('/',$sytx);
	   $symbol = $txarr[0];
	   $market = strtolower($txarr[0].$txarr[1]);
	   if($symbol == ''){
	       $symbol = 'btc';
	   }
	   if($market == ''){
	       $market = 'btcusdt';
	   }
	   $upmarket = strtoupper($symbol)."/USDT";
       $this->assign('upmarket',$upmarket);
	   $this->assign('market', $market);
	   $this->assign('smybol',$symbol);
	   
	   $lowercoin = strtolower($symbol);
       $cmarket = M("ctmarket")->where(array('coinname'=>$lowercoin))->field("state")->find();
       $state = $cmarket['state'];
       $this->assign('state',$state);
	   $this->display(); 
	}
        
     //获取行情数据
    public function getprice($api){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt ($ch, CURLOPT_URL, $api );
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT,10);
        $result = json_decode(curl_exec($ch),true);
        return $result;
    }
}
?>