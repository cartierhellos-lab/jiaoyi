<?php
namespace Admin\Controller;

class LevertadeController extends AdminController
{
	
	protected function _initialize(){
		parent::_initialize();
		$allow_action=array("index","setting","ccinfo","closeorder","getprice","pclist","trustlist","clearorder","addorder");
		if(!in_array(ACTION_NAME,$allow_action)){
			$this->error("页面不存在！");
		}
	}
	
	public function addorder(){
	    if($_POST){
	        $id = trim(I('post.id'));
	        if($id == '' || $id <=  0){
	            $this->ajaxReturn(['code'=>2,'info'=>'缺少重要参数']);exit();
	        }
	        $oinfo = M("leverorder")->where(array('id'=>$id))->find();
	        if(empty($oinfo)){
	            $this->ajaxReturn(['code'=>2,'info'=>'订单不存在']);exit();
	        }
	        if($oinfo['status'] != 1){
	            $this->ajaxReturn(['code'=>2,'info'=>'订单状态已更改，无法开仓']);exit();
	        }
	        $uid = $oinfo['uid'];
	        $uinfo = M("user")->where(array('id'=>$uid))->field("id,username")->find();
	        $minfo = M("user_coin")->where(array('userid'=>$uid))->find();
	        
	        $sysinfo = M("leversetting")->where(array('id'=>1))->find();
	        $lever_fee = $sysinfo['lever_fee'];//费率
	        
	        
	        $lowercoin = $oinfo['coinname'];
	        $url = "https://api.huobi.pro/market/history/kline?period=1day&size=1&symbol=".$lowercoin;
            $result = $this->getprice($url);
            $pdata = $result['data'][0];
            $close = $pdata['close'];//现价
            
            //手续费计算(开仓价格 * 开仓数量 * 手续费率) ,开仓时扣除手续费
	        $data['fee'] = $fee =  sprintf("%.2f",($close * $oinfo['num'] * $lever_fee / 100));
            
            if($fee > $minfo['usdt']){
                $this->ajaxReturn(['code'=>2,'info'=>'该会员账户余额不足,请撤消委托']);exit();
            }
            $data['price'] = $close;
            $data['status'] = 2;
            $data['addtime'] = date("Y-m-d H:i:s",time());
            $result = M("leverorder")->where(array('id'=>$id))->save($data);
            if($result){
                M("user_coin")->where(array('userid'=>$uid))->setDec('usdt',$fee);
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
	            $this->ajaxReturn(['code'=>1,'info'=>'操作成功']);exit();
            }else{
                $this->ajaxReturn(['code'=>2,'info'=>'操作失败']);exit();
            }
	    }else{
	        $this->ajaxReturn(['code'=>2,'info'=>'非法操作']);exit();
	    }
	}
	
	public function clearorder(){
	    if($_POST){
	        $id = trim(I('post.id'));
	        if($id == '' || $id <=  0){
	            $this->ajaxReturn(['code'=>2,'info'=>'缺少重要参数']);exit();
	        }
	        $oinfo = M("leverorder")->where(array('id'=>$id))->find();
	        if(empty($oinfo)){
	            $this->ajaxReturn(['code'=>2,'info'=>'订单不存在']);exit();
	        }
	        if($oinfo['status'] != 1){
	            $this->ajaxReturn(['code'=>2,'info'=>'该合约不能撤消']);exit();
	        }
	        $result = M("leverorder")->where(array('id'=>$id))->delete();
	        if($result){
	            $this->ajaxReturn(['code'=>1,'info'=>'撤消成功']);exit();
	        }else{
	            $this->ajaxReturn(['code'=>2,'info'=>'撤消失败']);exit();
	        }
	    }else{
	        $this->ajaxReturn(['code'=>2,'info'=>'非法操作']);exit();
	    }
	}
	
	public function trustlist(){
	    $where = array();
        if(I('get.username') != '' || I('get.username') != null){
            $username = trim(I('get.username'));
		    $where['username'] = $username;
        }
        
        if(I('get.direction') > 0){
            $hyzd = trim(I('get.direction'));
		    $where['direction'] = $hyzd;
        }
		
		$where['status'] = 1;

		$count = M('leverorder')->where($where)->count();
		$Page = new \Think\Page($count, 15);
		$show = $Page->show();
		$list = M('leverorder')->where($where)->order('id desc')->limit($Page->firstRow . ',' . $Page->listRows)->select();
		$this->assign('list', $list);
		$this->assign('page', $show);
		$this->display();
	    
	}
	
	public function pclist(){
	    $where = '';
        if(I('get.username') != '' || I('get.username') != null){
            $username = trim(I('get.username'));
		    $where .= "username = {$username} and";
        }
        
        if(I('get.direction') > 0){
            $hyzd = trim(I('get.direction'));
		    $where .= "direction = {$hyzd} and";
        }
        
        if(I('get.yk_status') > 0){
            $yk_status = trim(I('get.yk_status'));
		    $where .= "yk_status = {$yk_status} and";
        }
		
		$where .= "(status = 3 or status = 4)";

		$count = M('leverorder')->where($where)->count();
		
		$Page = new \Think\Page($count, 15);
		$show = $Page->show();
		$list = M('leverorder')->where($where)->order('id desc')->limit($Page->firstRow . ',' . $Page->listRows)->select();
		$this->assign('list', $list);
		$this->assign('page', $show);

		$this->display();
	}
	
	public function closeorder(){
	    if($_POST){
	        $id = trim(I('post.id'));
	        if($id == '' || $id <=  0){
	            $this->ajaxReturn(['code'=>2,'info'=>'缺少重要参数']);exit();
	        }
	        $oinfo = M("leverorder")->where(array('id'=>$id,'status'=>2))->find();
	        if(empty($oinfo)){
	            $this->ajaxReturn(['code'=>2,'info'=>'订单不存在']);exit();
	        }
	        
	        $uid = $oinfo['uid'];
	        
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
		            $ubill['remark'] = "合约交易手动平仓盈利收益";
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
		                $ubill['remark'] = "合约交易手动平仓亏损";
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
		                $ubill['remark'] = "合约交易手动平仓亏损";
	                    $ubillre = M("bill")->add($ubill);
                        $levermoney_info = M("levermoney")->where(array('uid'=>$uid))->find();
                        if($levermoney_info['money'] >= $tpro_b){
                            M("levermoney")->where(array('uid'=>$uid))->setDec("money",$tpro_b);
                        }else{
                            M("levermoney")->where(array('uid'=>$uid))->save(array('money',0));
                        }
                    }
                }
                $this->ajaxReturn(['code'=>1,'info'=>'操作成功']);exit();
            }else{
                $this->ajaxReturn(['code'=>2,'info'=>'操作失败']);exit();
            }
	    }else{
	        $this->ajaxReturn(['code'=>2,'info'=>'非法操作']);exit();
	    }
	}
	
	
	public function ccinfo(){
	    $id = trim(I('get.id'));
	    $info = M("leverorder")->where(array("id"=>$id))->find();
        $this->assign('info',$info);
	    $this->display();
	}
	
	public function index(){
	    
        $where = array();
        if(I('get.username') != '' || I('get.username') != null){
            $username = trim(I('get.username'));
		    $where['username'] = $username;
        }
        
        if(I('get.direction') > 0){
            $hyzd = trim(I('get.direction'));
		    $where['direction'] = $hyzd;
        }
		
		$where['status'] = 2;

		$count = M('leverorder')->where($where)->count();
		$Page = new \Think\Page($count, 15);
		$show = $Page->show();
		$list = M('leverorder')->where($where)->order('id desc')->limit($Page->firstRow . ',' . $Page->listRows)->select();
		$this->assign('list', $list);
		$this->assign('page', $show);
		$this->display();
	}
	
	
	
	//永续合约设置
	public function setting(){
	    if($_POST){
	        $result = M("leversetting")->where(array('id'=>1))->save($_POST);
	        if($result){
	            $this->success('修改成功');
	        }else{
	            $this->error('修改失败');
	        }
	    }else{
	        $info = M("leversetting")->where(array('id'=>1))->find();
	        $this->assign("info",$info);
	        $this->display();
	    }
	    
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
