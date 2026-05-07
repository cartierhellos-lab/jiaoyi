<?php
namespace Home\Controller;

class AutoexeController extends \Think\Controller
{
	protected function _initialize()
	{
		$allow_action = array("hycarryout","getnewprice","setwl","setwl_ty","autokjsy","releasedjprofit","autoxjtade","authsharesjsy","releaseissue","hycarryout_ty","AutoCreateOrder","AutoCloseOrder");
		if(!in_array(ACTION_NAME,$allow_action)){
			$this->error("非法操作！");
		}
	}
	
	//永续合约自动结算
	public function AutoCloseOrder(){
	    $list = M("leverorder")->where(array('status'=>2))->select();
	    if(!empty($list)){
	        $sysinfo = M("leversetting")->where(array('id'=>1))->find();
	        $lever_fee = $sysinfo['lever_fee'];//费率
	        foreach($list as $key=>$vo){
	            $id = $vo['id'];
	            $uid = $vo['uid'];
	            $uinfo = M("user")->where(array('id'=>$uid))->field("id,username")->find();
	            $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	            $bond_money_info = M("levermoney")->where(array('uid'=>$uid))->find();
	            $lowercoin = $vo['coinname'];
	            
	            $url = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$lowercoin;
                $close = $this->getnewprice($url);
                $price = $vo['price']; //开仓价格
                $direction = $vo['direction']; //交易方向
                $zsprice = $vo['zsprice']; //止损价格
                $zyprice = $vo['zyprice']; //止盈价格
                if($direction == 1){//做多(买涨) 现价大于等于止盈价格就结算 
                    if($zyprice > 0){ //说明设置了止盈价格
                        if($close >= $zyprice){
                            //收益计算
	                        //例：BTC/USDT永续合约开多仓100张，成交价格为10000 USDT，以11000 USDT价格平仓，
                            //则：平仓盈亏 = 收益（平仓） =（ 11000 – 10000 ）* 100 * 0.001 = 100 USDT。
                            $profit = sprintf("%.2f",(($close - $price) * 100 * 0.001 * $vo['ggan']));
                            $tprofit = abs($profit);

                            $data['pc_price'] = $close;
                            $data['status'] = 3;
                            $data['yk_status'] = 1;
                            $data['ylmoney'] = $tprofit;
                            $data['endtime'] = date("Y-m-d H:i:s",time());
                            $result = M("leverorder")->where(array('id'=>$id))->save($data);
                            
                            $ubill['uid'] = $uid;
		                    $ubill['username'] = $uinfo['username'];
		                    $ubill['num'] = $tprofit;
		                    $ubill['coinname'] = "usdt";
		                    $ubill['afternum'] = $minfo['usdt'] + $tprofit;
		                    $ubill['type'] = 20;
		                    $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                    $ubill['st'] = 1;
		                    $ubill['remark'] = L('合约交易止盈平仓盈利收益');
	                        $ubillre = M("bill")->add($ubill);
	                        if($result && $ubillre){
	                            M("user_coin")->where(array('userid'=>$uid))->setInc('usdt',$tprofit);
	                            echo "=订单ID:".$id.",止盈平仓=";
	                        }
                        }
                    }else{ //如果行情下跌 直到爆仓结算
                        //风险度=（持仓保证金/合约账户权益）*100%，
                        //合约账户权益=持仓保证金+持仓浮动盈亏+当前账户可用金额
                        //当风险度等于100%时，您的仓位被视为爆仓
                        //如果现价下跌，现价 - 开仓价格为负数
                        $profit_fd = sprintf("%.2f",(($close -  $price) * 100 * 0.001 * $vo['ggan']));
                        $account_qy = $bond_money_info['money'] + $profit_fd + $minfo['usdt'];//账户权益
                        $risk = $bond_money_info['money'] / $account_qy;
                        if($risk >= 1){ //如果风险度大于1刚爆仓，清零保证金，清零USDT资
                            $data['pc_price'] = $close;
                            $data['status'] = 4;
                            $data['yk_status'] = 2;
                            $data['ylmoney'] = $ks_money = $bond_money_info['money'] + $minfo['usdt'];
                            $data['endtime'] = date("Y-m-d H:i:s",time());
                            M("leverorder")->where(array('id'=>$id))->save($data);
                            
                            $ubill['uid'] = $uid;
		                    $ubill['username'] = $uinfo['username'];
		                    $ubill['num'] = $ks_money;
		                    $ubill['coinname'] = "usdt";
		                    $ubill['type'] = 20;
		                    $ubill['afternum'] = $minfo['usdt'] - $minfo['usdt'];
		                    $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                    $ubill['st'] = 1;
		                    $ubill['remark'] = L('合约交易爆仓,资产清零');
	                        $ubillre = M("bill")->add($ubill);
                            
                            M("user_coin")->where(array('userid'=>$uid))->save(array('usdt'=>0));
                            M("levermoney")->where(array('uid'=>$uid))->save(array('money',0));
                            echo "=订单ID:".$id.",爆仓=";
                        }
                    }
                }elseif($direction == 2){//做空(买涨) 现价小于开仓价格就结算 
                    if($zsprice > 0){ //说明设置了止损价格
                       if($close <=  $zsprice){ // 现价小于止损价格就结算 
                            //收益计算
	                        //例：BTC/USDT永续合约开多仓100张，成交价格为10000 USDT，以11000 USDT价格平仓，
                            //则：平仓盈亏 = 收益（平仓） =（ 11000 – 10000 ）* 100 * 0.001 = 100 USDT。
                            $profit = sprintf("%.2f",(($price - $close ) * 100 * 0.001 * $vo['ggan']));
                            $tprofit = abs($profit);
                            
                            if($minfo['usdt'] >= $tprofit){
                                M("user_coin")->where(array('userid'=>$uid))->setDec('usdt',$tprofit);
                                $ubill['afternum'] = $minfo['usdt'] - $tprofit;
                            }else{
                                $tpro_a = $minfo['usdt'];
                                $tpro_b = $tprofit - $minfo['usdt'];
                                M("user_coin")->where(array('userid'=>$uid))->setDec('usdt',$tpro_a);
                                $ubill['afternum'] = $minfo['usdt'] - $tpro_a;
                                $levermoney_info = M("levermoney")->where(array('uid'=>$uid))->find();
                                if($levermoney_info['money'] >= $tpro_b){
                                    M("levermoney")->where(array('uid'=>$uid))->setDec("money",$tpro_b);
                                }else{
                                    M("levermoney")->where(array('uid'=>$uid))->save(array('money',0));
                                }
                                
                            }
                            $data['pc_price'] = $close;
                            $data['status'] = 3;
                            $data['yk_status'] = 2;
                            $data['ylmoney'] = $tprofit;
                            $data['endtime'] = date("Y-m-d H:i:s",time());
                            $result = M("leverorder")->where(array('id'=>$id))->save($data);
                            
                            $ubill['uid'] = $uid;
		                    $ubill['username'] = $uinfo['username'];
		                    $ubill['num'] = $tprofit;
		                    $ubill['coinname'] = "usdt";
		                    $ubill['type'] = 20;
		                    $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                    $ubill['st'] = 1;
		                    $ubill['remark'] = L('合约交易止损平仓收益');
	                        $ubillre = M("bill")->add($ubill);

	                        if($result && $ubillre){
	                            echo "=订单ID:".$id.",止损平仓=";
	                        }
                            
                        }else{//如果价格上涨 直至 爆仓操作
                            //风险度=（持仓保证金/合约账户权益）*100%，
                            //合约账户权益=持仓保证金+持仓浮动盈亏+当前账户可用金额
                            //当风险度等于100%时，您的仓位被视为爆仓
                            //如果现价上涨，开仓价格-现价为负数
                            $profit_fd = sprintf("%.2f",(($price - $close ) * 100 * 0.001 * $vo['ggan']));
                            $account_qy = $bond_money_info['money'] + $profit_fd + $minfo['usdt'];//账户权益
                            $risk = $bond_money_info['money'] / $account_qy;
                            if($risk >= 1){ //如果风险度大于1则爆仓，清零保证金，清零USDT资
                                $data['pc_price'] = $close;
                                $data['status'] = 4;
                                $data['yk_status'] = 2;
                                $data['ylmoney'] = $ks_money = $bond_money_info['money'] + $minfo['usdt'];
                                $data['endtime'] = date("Y-m-d H:i:s",time());
                                M("leverorder")->where(array('id'=>$id))->save($data);
                                
                                $ubill['uid'] = $uid;
		                        $ubill['username'] = $uinfo['username'];
		                        $ubill['num'] = $ks_money;
		                        $ubill['coinname'] = "usdt";
		                        $ubill['type'] = 20;
		                        $ubill['afternum'] = $minfo['usdt'] - $minfo['usdt'];
		                        $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                        $ubill['st'] = 1;
		                        $ubill['remark'] = L('合约交易爆仓,资产清零');
	                            $ubillre = M("bill")->add($ubill);
                                
                                M("user_coin")->where(array('userid'=>$uid))->save(array('usdt'=>0));
                                M("levermoney")->where(array('uid'=>$uid))->save(array('money',0));
                                echo "=订单ID:".$id.",爆仓=";
                            }
                            
                        } 
                    }else{//如果价格上涨 直至 爆仓操作
                        $profit_fd = sprintf("%.2f",(($price - $close ) * 100 * 0.001 * $vo['ggan']));
                        $account_qy = $bond_money_info['money'] + $profit_fd + $minfo['usdt'];//账户权益
                        $risk = $bond_money_info['money'] / $account_qy;
                        if($risk >= 1){ //如果风险度大于1刚爆仓，清零保证金，清零USDT资
                            $data['pc_price'] = $close;
                            $data['status'] = 4;
                            $data['yk_status'] = 2;
                            $data['ylmoney'] = $ks_money = $bond_money_info['money'] + $minfo['usdt'];
                            $data['endtime'] = date("Y-m-d H:i:s",time());
                            M("leverorder")->where(array('id'=>$id))->save($data);
                            
                            $ubill['uid'] = $uid;
		                    $ubill['username'] = $uinfo['username'];
		                    $ubill['num'] = $ks_money;
		                    $ubill['coinname'] = "usdt";
		                    $ubill['type'] = 20;
		                    $ubill['afternum'] = $minfo['usdt'] - $minfo['usdt'];
		                    $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                    $ubill['st'] = 1;
		                    $ubill['remark'] = L('合约交易爆仓,资产清零');
	                        $ubillre = M("bill")->add($ubill);
                            
                            M("user_coin")->where(array('userid'=>$uid))->save(array('usdt'=>0));
                            M("levermoney")->where(array('uid'=>$uid))->save(array('money',0));
                            echo "=订单ID:".$id.",爆仓=";
                        }
                    }
                }
	        }
	    }
	}
	
	//永续合约委托定单自动建仓
	public function AutoCreateOrder(){
	    $list = M("leverorder")->where(array('status'=>1))->select();
	    if(!empty($list)){
	        $sysinfo = M("leversetting")->where(array('id'=>1))->find();
	        $lever_fee = $sysinfo['lever_fee'];//费率
	        foreach($list as $key=>$vo){
	            $id = $vo['id'];
	            $uid = $vo['uid'];
	            $uinfo = M("user")->where(array('id'=>$uid))->field("id,username")->find();
	            $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	            
	            $lowercoin = $vo['coinname'];
	            
	            $url = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$lowercoin;
                $close = $this->getnewprice($url);
                //手续费计算(开仓价格 * 开仓数量 * 手续费率) ,开仓时扣除手续费
                $wt_price = $vo['wt_price']; //委托价格
                $direction = $vo['direction']; //交易方向
                if($direction == 1){ //做多（上涨） 当前价小于等于委托价格就开仓  要跌了才开仓
                    if($close <=  $wt_price){
                        $data['fee'] = $fee =  sprintf("%.2f",($close * $vo['num'] * $lever_fee / 100));
	                    if($minfo['usdt'] >= $fee){
                            $data['price'] = $close;
                            $data['status'] = 2;
                            $data['addtime'] = date("Y-m-d H:i:s",time());
                            $re_a = M("leverorder")->where(array('id'=>$id))->save($data);
                            $re_b = M("user_coin")->where(array('userid'=>$uid))->setDec('usdt',$fee);
                            $ubill['uid'] = $uid;
		                    $ubill['username'] = $uinfo['username'];
		                    $ubill['num'] = $fee;
		                    $ubill['coinname'] = "usdt";
		                    $ubill['afternum'] = $minfo['usdt'] - $fee;
		                    $ubill['type'] = 19;
		                    $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                    $ubill['st'] = 2;
		                    $ubill['remark'] = L('合约交易开仓手续费');
	                        $re_c = M("bill")->add($ubill);
	                        if($re_a && $re_b && $re_c){
	                            echo "=订单ID:".$id.",开仓成功=";
	                        }
                        }
                    }else{
                        echo "=订单ID:".$id.",做多，价格不合适=";
                    }
                    
                }else if($direction == 2){ //做空(下跌) 当前价大于等于委托价格就开仓  要涨了才开仓
                    if($close >=  $wt_price){
                        $data['fee'] = $fee =  sprintf("%.2f",($close * $vo['num'] * $lever_fee / 100));
	                    if($minfo['usdt'] >= $fee){
                            $data['price'] = $close;
                            $data['status'] = 2;
                            $data['addtime'] = date("Y-m-d H:i:s",time());
                            $re_a = M("leverorder")->where(array('id'=>$id))->save($data);
                            $re_b = M("user_coin")->where(array('userid'=>$uid))->setDec('usdt',$fee);
                            $ubill['uid'] = $uid;
		                    $ubill['username'] = $uinfo['username'];
		                    $ubill['num'] = $fee;
		                    $ubill['coinname'] = "usdt";
		                    $ubill['afternum'] = $minfo['usdt'] - $fee;
		                    $ubill['type'] = 19;
		                    $ubill['addtime'] = date("Y-m-d H:i:s",time());
		                    $ubill['st'] = 2;
		                    $ubill['remark'] = L('合约交易开仓手续费');
	                        $re_c = M("bill")->add($ubill);
	                        if($re_a && $re_b && $re_c){
	                            echo "=订单ID:".$id.",开仓成功=";
	                        }
                        }
                    }else{
                         echo "=订单ID:".$id.",做空，价格不合适=";
                    }
                }
	        }
	    }
	}
	
	//自动释放冻结的认购币,设置计划任务，每天执行一次
	public function releaseissue(){
	    $nowday = date("Y-m-d",time());
	    $map['status'] = 1;
	    $map['endday'] = array('elt',$nowday);
	    $list = M("issue_log")->where($map)->select();
	    if(!empty($list)){
	        foreach($list as $key=>$vo){
	            $id = $vo['id'];
	            $uid = $vo['uid'];
	            $num = $vo['num'];
	            $cname = trim($vo['coinname']);
	            $cnamed = trim($vo['coinname'])."d";
	            //修改记录状态
	            $result = M("issue_log")->where(array('id'=>$id))->save(array('status'=>2));
	            if($result){
	                $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	                //扣除冻结的资产
	                M("user_coin")->where(array('userid'=>$uid))->setDec($cnamed,$num);
	                //增加可用资产的数量
	                M("user_coin")->where(array('userid'=>$uid))->setInc($cname,$num);
	                //写入日志
	                $data['uid'] = $uid;
	                $data['username'] = $vo['account'];
	                $data['num'] = $num;
	                $data['coinname'] = $cname;
	                $data['afternum'] = $minfo[$cname] + $num;
	                $data['type'] = 18;
	                $data['addtime'] = date("Y-m-d H:i:s",time());
	                $data['st'] = 1;
	                $data['remark'] = L("认购资产释放");
	                M("bill")->add($data);
	                echo "==认购记录ID:".$id."释放成功";
	            }else{
	                echo "==认购记录ID:".$id."释放失败";
	            }
	            
	        }
	    }else{
	        echo "==没有可释放认购记录==";
	    }
	}
	
	

	//委托订单自动交易
	//设置成5-10秒执行一次的计划任务
	public function autoxjtade(){
	    $list = M("bborder")->where(array('ordertype'=>1,'status'=>1))->select();
	    if(!empty($list)){
	        foreach($list as $k=>$v){
	            $type = $v['type'];
	            $uid = $v['uid'];
	            $id = $v['id'];
	            $symbol = strtolower($v['coin']).'usdt';
	            $lowercoin = strtolower($v['coin']);
	            
	            //限价单价
	            $xjprice = $v['xjprice'];
	            $sxfbl = $v['sxfbl'];
	            if($lowercoin == "ukb"){
	                $priceinfo = M("market")->where(array('name'=>"ukb_usdt"))->field("new_price")->find();
	                $newprice = $priceinfo['new_price'];
	            }else{
	                //获取当前行情价
	                $coinapi = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$symbol;
	                $newprice = $this->getnewprice($coinapi);
	            }

	            //买入，当行情价小于等于限价时则交易
	            $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	            if($type == 1){
	                $usdtnum = $v['usdtnum'];
	                if($newprice <= $xjprice){
	                    //计算能够买到的量
	                    $buy_coinnum = sprintf("%.8f",($usdtnum / $newprice));
	                    //手续费
	                    $fee = $buy_coinnum * $sxfbl / 100;
	                    //实际到账号的金额
	                    $tcoinnum = $buy_coinnum - $fee;
	                    //更新订单
	                    $data['coinnum'] = $tcoinnum;
	                    $data['price'] = $newprice;
	                    $data['tradetime'] = date("Y-m-d H:i:s",time());
	                    $data['fee'] = $fee;
	                    $data['status'] = 2;
	                    $savere = M("bborder")->where(array('id'=>$id))->save($data);
	                    //增加购买数量并写入日志
	                    $incre = M("user_coin")->where(array('userid'=>$uid))->setInc($lowercoin,$tcoinnum);
	                    $cincbill['uid'] = $uid;
	                    $cincbill['username'] = $v['account'];
	                    $cincbill['num'] = $tcoinnum;
	                    $cincbill['coinname'] = $lowercoin;
	                    $cincbill['afternum'] = $minfo[$lowercoin] + $tcoinnum;
	                    $cincbill['type'] = 10;
	                    $cincbill['addtime'] = date("Y-m-d H:i:s",time());
	                    $cincbill['st'] = 1;
	                    $cincbill['remark'] = L('币币交易限价购买委托成交');
	                    $cincre = M("bill")->add($cincbill);

	                    //扣除冻结的USDT并写入日志
	                    $decre = M("user_coin")->where(array('userid'=>$uid))->setDec("usdtd",$usdtnum);
                        $uincbill['uid'] = $uid;
	                    $uincbill['username'] = $v['account'];
	                    $uincbill['num'] = $usdtnum;
	                    $uincbill['coinname'] = "usdt";
	                    $uincbill['afternum'] = $minfo['usdt'] - $usdtnum;
	                    $uincbill['type'] = 9;
	                    $uincbill['addtime'] = date("Y-m-d H:i:s",time());
	                    $uincbill['st'] = 2;
	                    $uincbill['remark'] = L('币币交易限价购买委托成交');
	                    $uincre = M("bill")->add($uincbill);
	                    
	                    if($savere && $cincre && $uincre){
	                        
	                        $notice['uid'] = $uid;
		                    $notice['account'] = $v['account'];
		                    $notice['title'] = L('币币交易限价委托交易');
		                    $notice['content'] = L('币币交易限价购买委托订单购买成功');
		                    $notice['addtime'] = date("Y-m-d H:i:s",time());
		                    $notice['status'] = 1;
		                    M("notice")->add($notice);
	                        
	                        echo "==委托订单ID：".$id.",购买成功==";
	                    }
	                }else{
	                    echo "==委托订单ID：".$id.",没有达到限价购买价格==";
	                }
	            
	            //卖出，当行情价大于等于限价时则交易    
	            }elseif($type == 2){
	                $coinnum = $v['coinnum']; 
	                if($newprice >= $xjprice){
	                    //求出卖出所得的USDT量
	                    $allusdt = sprintf("%.8f",($coinnum * $newprice));
	                    //求出手续费
	                    $fee = $allusdt *  $sxfbl / 100;
	                    //求出实际到账USDT量
	                    $tusdtnum = $allusdt - $fee;
	                    //更新订单
	                    $data['usdtnum'] = $tusdtnum;
	                    $data['price'] = $newprice;
	                    $data['tradetime'] = date("Y-m-d H:i:s",time());
	                    $data['fee'] = $fee;
	                    $data['status'] = 2;
	                    $savere = M("bborder")->where(array('id'=>$id))->save($data);
	                    //增加卖出所得的USDT量并写入日志
	                    $incre = M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$tusdtnum);
	                    $uincbill['uid'] = $uid;
	                    $uincbill['username'] = $v['account'];
	                    $uincbill['num'] = $tusdtnum;
	                    $uincbill['coinname'] = 'usdt';
	                    $uincbill['afternum'] = $minfo['usdt'] + $tusdtnum;
	                    $uincbill['type'] = 9;
	                    $uincbill['addtime'] = date("Y-m-d H:i:s",time());
	                    $uincbill['st'] = 1;
	                    $uincbill['remark'] = L('币币交易限价出售委托成交');
	                    $uincre = M("bill")->add($uincbill);
	                    
	                    //扣除冻结的卖出币量并写入日志
	                    $decre = M("user_coin")->where(array('userid'=>$uid))->setDec($lowercoin."d",$coinnum);
                        $cincbill['uid'] = $uid;
	                    $cincbill['username'] = $v['account'];
	                    $cincbill['num'] = $coinnum;
	                    $cincbill['coinname'] = $lowercoin;
	                    $cincbill['afternum'] = $minfo[$lowercoin] - $coinnum;
	                    $cincbill['type'] = 10;
	                    $cincbill['addtime'] = date("Y-m-d H:i:s",time());
	                    $cincbill['st'] = 2;
	                    $cincbill['remark'] = L('币币交易限价出售委托成交');
	                    $cincre = M("bill")->add($cincbill);
	                    
	                    if($savere && $cincre && $uincre){
	                        
	                        $notice['uid'] = $uid;
		                    $notice['account'] = $v['account'];
		                    $notice['title'] = L('币币交易限价委托交易');
		                    $notice['content'] = L('币币交易限价购买委托订单出售成功');
		                    $notice['addtime'] = date("Y-m-d H:i:s",time());
		                    $notice['status'] = 1;
		                    M("notice")->add($notice);
	                        
	                        echo "==委托订单ID：".$id.",出售成功==";
	                    }
	                    
	                }else{
	                    echo "==委托订单ID：".$id.",没有达到限价出售价格==";
	                }
	            }
	        }
	    }else{
	        echo "没有限价委托可交易！";
	    }
	}
	
	
	//释放冻结的矿机收益币
	//设置一天执行一次的计划任务
	public function releasedjprofit(){
	    $nowday = date("Y-m-d",time());
	    $where['thawday'] = array('elt',$nowday);
	    $where['status'] = array('eq',1);
	    $list = M("djprofit")->where($where)->select();
	    if(!empty($list)){
	        foreach($list as $key=>$vo){
	            $id = $vo['id'];
	            $uid = $vo['uid'];
	            $username = $vo['username'];
	            $num = $vo['num'];
	            $coinname = trim($vo['coin']);
	            $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	            //修改冻结状态
	            M("djprofit")->where(array('id'=>$id))->save(array('status'=>2));
	            //添加财务日志
	            $billdata['uid'] = $uid;
	            $billdata['username'] = $username;
	            $billdata['num'] = $num;
	            $billdata['coinname'] = $coinname;
	            $billdata['afternum'] = $minfo[$coinname] + $num;
	            $billdata['type'] = 8;
	            $billdata['addtime'] = date("Y-m-d H:i:s",time());
	            $billdata['st'] = 1;
	            $billdata['remark'] = L('释放冻结收益');
	            M("bill")->add($billdata);
	            //增加会员资产，减少冻结额度
	            $coinname_d = $coinname."d";
	            M("user_coin")->where(array('userid'=>$uid))->setDec($coinname_d,$num);
	            M("user_coin")->where(array('userid'=>$uid))->setInc($coinname,$num);
	            
	            
	            $notice['uid'] = $uid;
		        $notice['account'] = $username;
		        $notice['title'] = L('释放冻结收益');
		        $notice['content'] = L('您冻结的矿机收益释放成功，可以交易');
		        $notice['addtime'] = date("Y-m-d H:i:s",time());
		        $notice['status'] = 1;
		        M("notice")->add($notice);
	            
	            
	            
	            echo "==ID:".$id."释放".$num.$coinname."成功==";
	            echo "<br />";
	        }
	    }else{
	        echo "====没有可释放的冻结记录====";
	    }
	}
	
	//共享矿机自动结算收益，设置一天执行一次的计划任务
	public function authsharesjsy(){
	    $kjlist = M("kjorder")->where(array('status'=>1,'type'=>2))->select();
	    if(!empty($kjlist)){
	        foreach($kjlist as $key=>$vo){
	           $id = $vo['id'];
	           $uid = $vo['uid'];
	           $username = $vo['username'];
	           $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	           $kid = $vo['kid'];
	           $nowdate = date("Y-m-d",time());
	           $profitinfo = M("kjprofit")->where(array('uid'=>$uid,'kid'=>$id,'day'=>$nowdate))->find();
	           if(empty($profitinfo)){
	               
	               $sharbltxt = $vo['sharbltxt'];
	               
	               if($sharbltxt <= 0){
	                   
	                    echo "===共享矿机ID".$id."共享码有误===";
	                   
	               }else{
	                    $sharekj = M("kjorder")->where(array('sharbltxt'=>$sharbltxt))->count();
	                    if($sharekj >= 2){
	                        //查找矿机收益的类型以及查找收益是否需要冻结及冻结天数
	                         $outtype = $vo['outtype'];
	                         if($outtype == 1){//按产值需要查找产出币种的最新行情
	                             $coinname = strtolower(trim($vo['outcoin']));
	                             $outnum = $vo['outusdt'];
	                             $symbol = $coinname.'usdt';
	                             $coinapi = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$symbol;
	                             $newprice = $this->getnewprice($coinapi);
                                 $tcoinnum = sprintf("%.6f",($outnum / $newprice)); //实际产生的币量，保留6位小数
	                         }elseif($outtype == 2){
	                             $coinname = strtolower(trim($vo['outcoin']));
	                             $tcoinnum = $vo['outnum'];
	                         }
	                         $djout = $vo['djout'];//1冻结2不冻结
	                         $djday = $vo['djnum'];//冻结天数
	                         //写入矿机收益日志
	                         $kjprofit_d['uid'] = $uid;
	                         $kjprofit_d['username'] = $username;
	                         $kjprofit_d['kid'] = $id;
	                         $kjprofit_d['ktitle'] = $vo['kjtitle'];
	                         $kjprofit_d['num'] = $tcoinnum;
	                         $kjprofit_d['coin'] = $coinname;
	                         $kjprofit_d['addtime'] = date("Y-m-d H:i:s",time());
	                         $kjprofit_d['day'] =  date("Y-m-d",time());
	                         M("kjprofit")->add($kjprofit_d);
	                         if($djout == 2){
	                             $coin_d = $coinname."d";
	                             M("user_coin")->where(array('userid'=>$uid))->setInc($coin_d,$tcoinnum);
	                             $djprofit_d['uid'] = $uid;
	                             $djprofit_d['username'] = $username;
	                             $djprofit_d['num'] = $tcoinnum;
	                             $djprofit_d['coin'] = $coinname;
	                             $djprofit_d['status'] = 1;
	                             $djprofit_d['addtime'] = date("Y-m-d H:i:s",time());
	                             $djprofit_d['addday'] = date("Y-m-d",time());
	                             $djprofit_d['thawtime'] = date("Y-m-d H:i:s",(time() + 86400 * $djday));
	                             $djprofit_d['thawday'] = date("Y-m-d",(time() + 86400 * $djday));
	                             $djprofit_d['remark'] = L('冻结矿机释放收益');
                
	                             M("djprofit")->add($djprofit_d);
	                             //写资金日志
	                             $billdata['uid'] = $uid;
	                             $billdata['username'] = $username;
	                             $billdata['num'] = $tcoinnum;
	                             $billdata['coinname'] = $coinname;
	                             $billdata['afternum'] = $minfo[$coin_d] + $tcoinnum;
	                             $billdata['type'] = 7;
	                             $billdata['addtime'] = date("Y-m-d H:i:s",time());
	                             $billdata['st'] = 1;
	                             $billdata['remark'] = L('矿机收益释放冻结');
	                             M("bill")->add($billdata);
	                             
	                             $notice['uid'] = $uid;
		                         $notice['account'] = $username;
		                         $notice['title'] = L('矿机收益');
		                         $notice['content'] = L('今日矿机收益已成功到账，请注册查收');
		                         $notice['addtime'] = date("Y-m-d H:i:s",time());
		                         $notice['status'] = 1;
		                         M("notice")->add($notice);
	                             
	                         }elseif($djout == 1){
	                             M("user_coin")->where(array('userid'=>$uid))->setInc($coinname,$tcoinnum);
	                             //写资金日志
	                             $billdata['uid'] = $uid;
	                             $billdata['username'] = $username;
	                             $billdata['num'] = $tcoinnum;
	                             $billdata['coinname'] = $coinname;
	                             $billdata['afternum'] = $minfo[$coinname] + $tcoinnum;
	                             $billdata['type'] = 8;
	                             $billdata['addtime'] = date("Y-m-d H:i:s",time());
	                             $billdata['st'] = 1;
	                             $billdata['remark'] = L('矿机收益释放');
	                             M("bill")->add($billdata);
	                         }
	                         
	                         //修改矿机收益次数
	                         M("kjorder")->where(array('id'=>$id))->setDec("synum",1);
	                         $reinfo = M("kjorder")->where(array('id'=>$id))->find();
	                         if($reinfo['synum'] < 1){
	                             M("kjorder")->where(array('id'=>$id))->save(array('status'=>3));
	                         }
	                         echo "==共享矿机ID:".$kid."收益成功==";
	                         echo "<br />";
        
	                    }else{
	                        echo "===共享矿机ID".$id."另一部分没有购买，不能收益===";
	                    }
	               }
	               
	           }else{
	               echo "==矿机ID:".$kid."不能重复收益==";
	               echo "<br />";
	           }
	           
	           
	       }
	    }
	    
	}
	
	//独资矿机自动收益，每天执行一次
	//设置一天执行一次的计划任务
	public function autokjsy(){
	   $kjlist = M("kjorder")->where(array('status'=>1,'type'=>1))->select();
	   if(!empty($kjlist)){
	       foreach($kjlist as $key=>$vo){
	           $id = $vo['id'];
	           $uid = $vo['uid'];
	           $username = $vo['username'];
	           $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	           $kid = $vo['kid'];
	           $nowdate = date("Y-m-d",time());
	           $profitinfo = M("kjprofit")->where(array('uid'=>$uid,'kid'=>$id,'day'=>$nowdate))->find();
	           if(empty($profitinfo)){
	               //查找矿机收益的类型以及查找收益是否需要冻结及冻结天数
	               $outtype = $vo['outtype'];
	               if($outtype == 1){//按产值需要查找产出币种的最新行情
	                   $coinname = strtolower(trim($vo['outcoin']));
	                   $outnum = $vo['outusdt'];
	                   $symbol = $coinname.'usdt';
	                   $coinapi = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$symbol;
	                   $newprice = $this->getnewprice($coinapi);
                       $tcoinnum = sprintf("%.6f",($outnum / $newprice)); //实际产生的币量，保留6位小数
	               }elseif($outtype == 2){
	                   $coinname = strtolower(trim($vo['outcoin']));
	                   $tcoinnum = $vo['outnum'];
	               }
	               $djout = $vo['djout'];//1冻结2不冻结
	               $djday = $vo['djnum'];//冻结天数
	               //写入矿机收益日志
	               $kjprofit_d['uid'] = $uid;
	               $kjprofit_d['username'] = $username;
	               $kjprofit_d['kid'] = $id;
	               $kjprofit_d['ktitle'] = $vo['kjtitle'];
	               $kjprofit_d['num'] = $tcoinnum;
	               $kjprofit_d['coin'] = $coinname;
	               $kjprofit_d['addtime'] = date("Y-m-d H:i:s",time());
	               $kjprofit_d['day'] =  date("Y-m-d",time());
	               M("kjprofit")->add($kjprofit_d);
	               if($djout == 2){
	                   $coin_d = $coinname."d";
	                   M("user_coin")->where(array('userid'=>$uid))->setInc($coin_d,$tcoinnum);
	                   $djprofit_d['uid'] = $uid;
	                   $djprofit_d['username'] = $username;
	                   $djprofit_d['num'] = $tcoinnum;
	                   $djprofit_d['coin'] = $coinname;
	                   $djprofit_d['status'] = 1;
	                   $djprofit_d['addtime'] = date("Y-m-d H:i:s",time());
	                   $djprofit_d['addday'] = date("Y-m-d",time());
	                   $djprofit_d['thawtime'] = date("Y-m-d H:i:s",(time() + 86400 * $djday));
	                   $djprofit_d['thawday'] = date("Y-m-d",(time() + 86400 * $djday));
	                   $djprofit_d['remark'] = L('冻结矿机释放收益');

	                   M("djprofit")->add($djprofit_d);
	                   //写资金日志
	                   $billdata['uid'] = $uid;
	                   $billdata['username'] = $username;
	                   $billdata['num'] = $tcoinnum;
	                   $billdata['coinname'] = $coinname;
	                   $billdata['afternum'] = $minfo[$coin_d] + $tcoinnum;
	                   $billdata['type'] = 7;
	                   $billdata['addtime'] = date("Y-m-d H:i:s",time());
	                   $billdata['st'] = 1;
	                   $billdata['remark'] = L('矿机收益释放冻结');
	                   M("bill")->add($billdata);
	                   
	                   $notice['uid'] = $uid;
		               $notice['account'] = $username;
		               $notice['title'] = L('矿机收益');
		               $notice['content'] = L('今日矿机收益已成功到账，请注册查收');
		               $notice['addtime'] = date("Y-m-d H:i:s",time());
		               $notice['status'] = 1;
		               M("notice")->add($notice);
	                   
	               }elseif($djout == 1){
	                   M("user_coin")->where(array('userid'=>$uid))->setInc($coinname,$tcoinnum);
	                   //写资金日志
	                   $billdata['uid'] = $uid;
	                   $billdata['username'] = $username;
	                   $billdata['num'] = $tcoinnum;
	                   $billdata['coinname'] = $coinname;
	                   $billdata['afternum'] = $minfo[$coinname] + $tcoinnum;
	                   $billdata['type'] = 8;
	                   $billdata['addtime'] = date("Y-m-d H:i:s",time());
	                   $billdata['st'] = 1;
	                   $billdata['remark'] = L('矿机收益释放');
	                   M("bill")->add($billdata);
	               }
	               
	               //修改矿机收益次数
	               M("kjorder")->where(array('id'=>$id))->setDec("synum",1);
	               $reinfo = M("kjorder")->where(array('id'=>$id))->find();
	               if($reinfo['synum'] < 1){
	                   M("kjorder")->where(array('id'=>$id))->save(array('status'=>3));
	               }
	               echo "==矿机ID:".$kid."收益成功==";
	               echo "<br />";
	           }else{
	               echo "==矿机ID:".$kid."不能重复收益==";
	               echo "<br />";
	           }
	           
	           
	       }
  
	   }else{
	       echo "++||没有正常运行的矿机||++";
	   }
	}
	
	//休验订单自动按风控比例设置订单的盈亏比例
	//设置成5-10秒执行一次的计划任务
	public function setwl_ty(){
	    $map['status'] = 1;
	    $map['kongyk'] = 0;
	    $orderobj = M("tyhyorder");
	    $count = $orderobj->where($map)->count();
	    $setting = M("hysetting")->where(array('id'=>1))->field("hy_fkgl")->find();
        if($setting['hy_fkgl'] > 0){
            $ylcount = intval($count * $setting['hy_fkgl'] / 100);
            
            $kscount = $count - $ylcount;
            if($ylcount > 0){
                $yllist = $orderobj->where($map)->order("num asc")->limit($ylcount)->select();
                if(!empty($yllist)){
                    foreach($yllist as $k=>$v){
                        $yid = $v['id'];
                        $orderobj->where(array('id'=>$yid))->save(array('kongyk'=>1));
                        echo "订单ID:".$yid."设为盈利==";
                    }
                }
            }
            
            if($kscount > 0){
                $kslist = $orderobj->where($map)->order("num asc")->limit($kscount)->select();
                if(!empty($kslist)){
                    foreach($kslist as $k=>$v){
                        $kid = $v['id'];
                        $orderobj->where(array('id'=>$kid))->save(array('kongyk'=>2));
                        echo "订单ID:".$kid."设为亏损==";
                    }
                }
            }
        }
        
        echo "操作成功";
	}
	
	
	//自动按风控比例设置订单的盈亏比例
	//设置成5-10秒执行一次的计划任务
	public function setwl(){
	    $map['status'] = 1;
	    $map['kongyk'] = 0;
	    $orderobj = M("hyorder");
	    $count = $orderobj->where($map)->count();
	    $setting = M("hysetting")->where(array('id'=>1))->field("hy_fkgl")->find();
        if($setting['hy_fkgl'] > 0){
            $ylcount = intval($count * $setting['hy_fkgl'] / 100);
            
            $kscount = $count - $ylcount;
            if($ylcount > 0){
                $yllist = $orderobj->where($map)->order("num asc")->limit($ylcount)->select();
                if(!empty($yllist)){
                    foreach($yllist as $k=>$v){
                        $yid = $v['id'];
                        $orderobj->where(array('id'=>$yid))->save(array('kongyk'=>1));
                        echo "订单ID:".$yid."设为盈利==";
                    }
                }
            }
            
            if($kscount > 0){
                $kslist = $orderobj->where($map)->order("num asc")->limit($kscount)->select();
                if(!empty($kslist)){
                    foreach($kslist as $k=>$v){
                        $kid = $v['id'];
                        $orderobj->where(array('id'=>$kid))->save(array('kongyk'=>2));
                        echo "订单ID:".$kid."设为亏损==";
                    }
                }
            }
        }
        
        echo "操作成功";
	}
	
	
		//自动结算合约订单
	public function hycarryout_ty(){
        $nowtime = time();	   
        $map['status'] = 1;
        $map['intselltime'] = array('elt',$nowtime);
        $orderobj = M("tyhyorder");
	    $list = $orderobj->where($map)->select();
	    
	    if(!empty($list)){
	        foreach($list as $key=>$vo){
	            $coinname = $vo['coinname'];
	            $coinarr = explode("/",$coinname);
	            $symbol = strtolower($coinarr[0]).strtolower($coinarr[1]);
	            $coinapi = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$symbol;
	            $newprice = $this->getnewprice($coinapi);
	            $randnum = "0.".rand(1000,9999);
	            $buyprice = $vo['buyprice'];
	            $otype = $vo['hyzd']; //合约方向
	            $dkong = $vo['kongyk']; //单控设置
	            $uid = $vo['uid'];//会员ID
	            $id = $vo['id'];//记录ID
	            $num = $vo['num'];
	            $hybl = $vo['hybl']; //收益比例 
	            $ylnum = $num * ($hybl / 100); //盈利金额
	            $money = $num + $ylnum;//本金+盈利金额
	            //$dkong分三种情况 1、0表示随行情，1表示盈利 2 表示亏损
	            //盈利时增加$money，，亏损时亏本金
	            if($dkong == 0){
	                //买涨
	                if($otype == 1){ //买跌
	                    if($newprice > $buyprice){ //盈利
	                        //增加资产
	                        //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                         M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        //$this->addlog($uid,$vo['username'],$money);
	                    }else{//亏损
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                }elseif($otype == 2){ //买跌
	                    if($newprice < $buyprice){ //盈利
	                        //增加资产
	                        //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                         M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        //$this->addlog($uid,$vo['username'],$money);
	                    }else{//亏损
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                    
	                }
	            }elseif($dkong == 1){//单控盈利
	                if($otype == 1){//买涨
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $newprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }

	                    //增加资产
	                    //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    //$this->addlog($uid,$vo['username'],$money);
	                    
	                }elseif($otype == 2){//买跌
	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    
	                    //增加资产
	                    //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    //$this->addlog($uid,$vo['username'],$money);
	                }
	            }elseif($dkong == 2){
	                if($otype == 1){//买涨
	                    //买涨,指定亏损,结算价格要低于买入价格
	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    
	                    
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                }elseif($otype == 2){//买跌
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice  + $randnum;
	                    }

	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                }
	            }
                echo "==订单ID:".$id."出售成功==";
	        }
	        
	    }else{
	        echo "没有订单可以结算！";
	    }
	    
	}
	
	
	//自动结算体验合约订单
	public function hycarryout_ty_old(){
        $nowtime = time();	   
        $map['status'] = 1;
        $map['intselltime'] = array('elt',$nowtime);
        $orderobj = M("tyhyorder");
	    $list = $orderobj->where($map)->select();
	    //获取合约参数
	    $setting = M("hysetting")->where(array('id'=>1))->field("hy_ksid,hy_ylid,hy_fkgl")->find();
	    //指定盈利ID组
	    $winarr = explode(',',$setting['hy_ylid']);
	    //指定亏损ID组
	    $lossarr = explode(',',$setting['hy_ksid']);
        //风控比例组
        $fkarr = explode(',',$setting['hy_fkgl']);
        
	    if(!empty($list)){
	        foreach($list as $key=>$vo){
	            $coinname = $vo['coinname'];
	            $coinarr = explode("/",$coinname);
	            $symbol = strtolower($coinarr[0]).strtolower($coinarr[1]);
	            $coinapi = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$symbol;
	            $newprice = $this->getnewprice($coinapi);
	            $randnum = "0.".rand(1000,9999);
	            $buyprice = $vo['buyprice'];
	            $otype = $vo['hyzd']; //合约方向
	            $dkong = $vo['kongyk']; //单控设置
	            $uid = $vo['uid'];//会员ID
	            $id = $vo['id'];//记录ID
	            $num = $vo['num'];
	            $hybl = $vo['hybl'];
	            $ylnum = $num * ($hybl / 100);
	            $money = $num + $ylnum;//盈利金额

	            //买涨
	            if($otype == 1){
	                if(in_array($uid,$winarr)){//如果有指定盈利ID，则按盈利结算
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $newprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }

	                    //增加资产
	                    //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    //$this->addlog($uid,$vo['username'],$money);
	                }elseif(in_array($uid,$lossarr)){//如果有指定亏损ID，则按亏损结算

	                    //买涨,指定亏损,结算价格要低于买入价格
	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    
	                    
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    
	                }else{//如果未指定盈利和亏损，则按单控的计算
	                    if($dkong == 1){//盈利
	                        
                            if($newprice > $buyprice){
	                            $sellprice = $newprice;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $newprice + $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $buyprice + $randnum;
	                        }
	                        
	                       // echo '买入价格:'.$buyprice;
	                       // echo "<br />";
	                       // echo  '结算价格:'.$sellprice;die;
	                        
	                        //增加资产
	                        //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                        M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        //$this->addlog($uid,$vo['username'],$money);
	                            
	                     }elseif($dkong == 2){//亏损
	                        if($newprice > $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $newprice;
	                        }
	                        
	                       // echo '买入价格:'.$buyprice;
	                       // echo "<br />";
	                       // echo  '结算价格:'.$sellprice;die;
	                        
	                        
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                }
	            //买跌    
	            }elseif($otype == 2){
	                
    
	                if(in_array($uid,$winarr)){//如果有指定盈利ID，则按盈利结算


	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    

	                    //增加资产
	                    //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    //$this->addlog($uid,$vo['username'],$money);
	                }elseif(in_array($uid,$lossarr)){//如果有指定亏损ID，则按亏损结算
	                   
	                
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice  + $randnum;
	                    }
	                    
	                   
	                    
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                }else{//如果未指定盈利和亏损，则按单控的计算
	                    if($dkong == 1){//盈利
                            if($newprice > $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $newprice;
	                        }

	                        //增加资产
	                        //M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                        M("user")->where(array('id'=>$uid))->setInc("money",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        //$this->addlog($uid,$vo['username'],$money);
	                            
	                     }elseif($dkong == 2){//亏损
	                        if($newprice > $buyprice){
	                            $sellprice = $newprice;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $buyprice + $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $buyprice  + $randnum;
	                        }
	                        
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                }
	                
	            }
	            
	          
	            echo "==订单ID:".$id."出售成功==";
	        }
	    }else{
	        echo "没有订单可以结算！";
	    }
	}
	
	//自动结算合约订单
	public function hycarryout(){
        $nowtime = time();	   
        $map['status'] = 1;
        $map['intselltime'] = array('elt',$nowtime);
        $orderobj = M("hyorder");
	    $list = $orderobj->where($map)->select();
	    
	    if(!empty($list)){
	        foreach($list as $key=>$vo){
	            $coinname = $vo['coinname'];
	            $coinarr = explode("/",$coinname);
	            $symbol = strtolower($coinarr[0]).strtolower($coinarr[1]);
	            $coinapi = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$symbol;
	            $newprice = $this->getnewprice($coinapi);
	            $randnum = "0.".rand(1000,9999);
	            $buyprice = $vo['buyprice'];
	            $otype = $vo['hyzd']; //合约方向
	            $dkong = $vo['kongyk']; //单控设置
	            $uid = $vo['uid'];//会员ID
	            $id = $vo['id'];//记录ID
	            $num = $vo['num'];
	            $hybl = $vo['hybl']; //收益比例 
	            $ylnum = $num * ($hybl / 100); //盈利金额
	            $money = $num + $ylnum;//本金+盈利金额
	            //$dkong分三种情况 1、0表示随行情，1表示盈利 2 表示亏损
	            //盈利时增加$money，，亏损时亏本金
	            if($dkong == 0){
	                //买涨
	                if($otype == 1){ //买跌
	                    if($newprice > $buyprice){ //盈利
	                        //增加资产
	                        M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        $this->addlog($uid,$vo['username'],$money);
	                    }else{//亏损
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                }elseif($otype == 2){ //买跌
	                    if($newprice < $buyprice){ //盈利
	                        //增加资产
	                        M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        $this->addlog($uid,$vo['username'],$money);
	                    }else{//亏损
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $newprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                    
	                }
	            }elseif($dkong == 1){//单控盈利
	                if($otype == 1){//买涨
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $newprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }

	                    //增加资产
	                    M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    $this->addlog($uid,$vo['username'],$money);
	                    
	                }elseif($otype == 2){//买跌
	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    
	                    //增加资产
	                    M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    $this->addlog($uid,$vo['username'],$money);
	                }
	            }elseif($dkong == 2){
	                if($otype == 1){//买涨
	                    //买涨,指定亏损,结算价格要低于买入价格
	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    
	                    
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                }elseif($otype == 2){//买跌
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice  + $randnum;
	                    }

	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                }
	            }
                echo "==订单ID:".$id."出售成功==";
	        }
	        
	    }else{
	        echo "没有订单可以结算！";
	    }
	    
	}
	
	
	
	//自动结算合约订单
	public function hycarryout____old(){
        $nowtime = time();	   
        $map['status'] = 1;
        $map['intselltime'] = array('elt',$nowtime);
        $orderobj = M("hyorder");
	    $list = $orderobj->where($map)->select();
	    
	    //获取合约参数
	    $setting = M("hysetting")->where(array('id'=>1))->field("hy_ksid,hy_ylid,hy_fkgl")->find();
	    //指定盈利ID组
	    $winarr = explode(',',$setting['hy_ylid']);
	    //指定亏损ID组
	    $lossarr = explode(',',$setting['hy_ksid']);
        //风控比例组
        $fkarr = explode(',',$setting['hy_fkgl']);
        
	    if(!empty($list)){
	        foreach($list as $key=>$vo){
	            $coinname = $vo['coinname'];
	            $coinarr = explode("/",$coinname);
	            $symbol = strtolower($coinarr[0]).strtolower($coinarr[1]);
	            $coinapi = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$symbol;
	            $newprice = $this->getnewprice($coinapi);
	            $randnum = "0.".rand(1000,9999);
	            $buyprice = $vo['buyprice'];
	            $otype = $vo['hyzd']; //合约方向
	            $dkong = $vo['kongyk']; //单控设置
	            $uid = $vo['uid'];//会员ID
	            $id = $vo['id'];//记录ID
	            $num = $vo['num'];
	            $hybl = $vo['hybl'];
	            $ylnum = $num * ($hybl / 100);
	            $money = $num + $ylnum;//盈利金额

	            //买涨
	            if($otype == 1){

	                if(in_array($uid,$winarr)){//如果有指定盈利ID，则按盈利结算
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $newprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }

	                    //增加资产
	                    M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    $this->addlog($uid,$vo['username'],$money);
	                }elseif(in_array($uid,$lossarr)){//如果有指定亏损ID，则按亏损结算

	                    //买涨,指定亏损,结算价格要低于买入价格
	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    
	                    
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    
	                }else{//如果未指定盈利和亏损，则按单控的计算
	                    if($dkong == 1){//盈利
	                        
                            if($newprice > $buyprice){
	                            $sellprice = $newprice;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $newprice + $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $buyprice + $randnum;
	                        }
	                        
	                       // echo '买入价格:'.$buyprice;
	                       // echo "<br />";
	                       // echo  '结算价格:'.$sellprice;die;
	                        
	                        //增加资产
	                        M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        $this->addlog($uid,$vo['username'],$money);
	                            
	                     }elseif($dkong == 2){//亏损
	                        if($newprice > $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $newprice;
	                        }
	                        
	                       // echo '买入价格:'.$buyprice;
	                       // echo "<br />";
	                       // echo  '结算价格:'.$sellprice;die;
	                        
	                        
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                }
	            //买跌    
	            }elseif($otype == 2){
	                

	                if(in_array($uid,$winarr)){//如果有指定盈利ID，则按盈利结算


	                    if($newprice > $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice - $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $newprice;
	                    }
	                    

	                    //增加资产
	                    M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 1;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $ylnum;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                    //写财务日志
	                    $this->addlog($uid,$vo['username'],$money);
	                }elseif(in_array($uid,$lossarr)){//如果有指定亏损ID，则按亏损结算
	                   
	                
	                    if($newprice > $buyprice){
	                        $sellprice = $newprice;
	                    }elseif($newprice == $buyprice){
	                        $sellprice = $buyprice + $randnum;
	                    }elseif($newprice < $buyprice){
	                        $sellprice = $buyprice  + $randnum;
	                    }
	                    
	                   
	                    
	                    //修改订单状态
	                    $sd['status'] = 2;
	                    $sd['is_win'] = 2;
	                    $sd['sellprice'] = $sellprice;
	                    $sd['ploss'] = $num;
	                    $orderobj->where(array('id'=>$id))->save($sd);
	                }else{//如果未指定盈利和亏损，则按单控的计算
	                    if($dkong == 1){//盈利
                            if($newprice > $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $buyprice - $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $newprice;
	                        }

	                        //增加资产
	                        M("user_coin")->where(array('userid'=>$uid))->setInc("usdt",$money);
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 1;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $ylnum;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                        //写财务日志
	                        $this->addlog($uid,$vo['username'],$money);
	                            
	                     }elseif($dkong == 2){//亏损
	                        if($newprice > $buyprice){
	                            $sellprice = $newprice;
	                        }elseif($newprice == $buyprice){
	                            $sellprice = $buyprice + $randnum;
	                        }elseif($newprice < $buyprice){
	                            $sellprice = $buyprice  + $randnum;
	                        }
	                        
	                        //修改订单状态
	                        $sd['status'] = 2;
	                        $sd['is_win'] = 2;
	                        $sd['sellprice'] = $sellprice;
	                        $sd['ploss'] = $num;
	                        $orderobj->where(array('id'=>$id))->save($sd);
	                    }
	                }
	                
	            }
	            echo "==订单ID:".$id."出售成功==";
	        }
	    }else{
	        echo "没有订单可以结算！";
	    }
	}
	
	
	
	
	
	//写财务日志
	public function addlog($uid,$username,$money){
	    $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	    $data['uid'] = $uid;
	    $data['username'] = $username;
	    $data['num'] = $money;
	    $data['coinname'] = "usdt";
	    $data['afternum'] = $minfo['usdt'] + $money;
	    $data['type'] = 4;
	    $data['addtime'] = date("Y-m-d H:i:s",time());
	    $data['st'] = 1;
	    $data['remark'] = L('合约出售');
	    M("bill")->add($data);
	    
	    $notice['uid'] = $uid;
		$notice['account'] = $username;
		$notice['title'] = L('快速合约交易');
		$notice['content'] = L('快速合约已平仓，请及时加仓');
		$notice['addtime'] = date("Y-m-d H:i:s",time());
		$notice['status'] = 1;
		M("notice")->add($notice);
	    
	    
	}
	
	
	//获取行情数据
    public function getnewprice($api){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt ($ch, CURLOPT_URL, $api );
        curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT,10);
        $result = json_decode(curl_exec($ch),true);
        $price_arr = $result['data'][0];
        $close = $price_arr['close'];//现价
        return $close;
    }
	

}
?>