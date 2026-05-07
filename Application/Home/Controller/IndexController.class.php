<?php
namespace Home\Controller;

class IndexController extends HomeController
{
	protected function _initialize()
	{
		parent::_initialize();
		$allow_action = array("index","gglist","gginfo");
		if (!in_array(ACTION_NAME,$allow_action)) {
			$this->error("非法操作！");
		}
	}

    //网站首页面
	public function index(){
        $list = M("ctmarket")->where(array('status'=>1))->field("coinname,id")->select();
        $this->assign("market",$list);
		$clist = M("config")->where(array('id'=>1))->field("wapsildea,wapsildeb,wapsildec,wapsilded,waplogo")->find();
		$this->assign('clist',$clist);
		
		$this->display();
	}
    
    //公告中心
    public function gglist(){
        
        $list = M("content")->where(array('status'=>1))->select();

        $this->assign("list",$list);
        $this->display();
    }
    //公告详情
    public function gginfo($id = null){
        
        if (checkstr($id)) {
			$this->error(L('您输入的信息有误'));
		}
        
        $info = M("content")->where(array('id'=>$id))->find();
        if(empty($info)){
            redirect('/Index/gglist.html');
        }
        $this->assign("info",$info);
        $this->display();
    }

}
?>