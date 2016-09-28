<?php
namespace Home\Controller;
use Logic\SphinxClient;
use Logic\DataApi;
defined('THINK_PATH') or exit();
class ListController extends CommonController
{
    //顶部查询
    //任意搜索列表页
    public function index()
    {
        $ids = array();
        $query = [];
        $valid = ['k', 'sort', 'bid', 'price'];     //有效的查询Key：关键字/排序/品牌/价格  类型
        $addTime = I('get.time');
        $goodsNo = I('get.n'); 		//商品号
        $goodsPsn = I('get.psn'); 	//货号
        foreach($_GET AS $queryName=>$queryValue) {
            // 查询全部时，直接忽略该条件
            if( ($_GET[$queryName] !== 'all') && in_array($queryName, $valid) ) {
                $query[$queryName] = I('get.'.$queryName);
            }
        }
        $where = array();
        $k     = $query['k'];
        $bid   = explode('_',$query['bid']);
        $sort  = $query['sort'];
        $price = explode('_',$query['price']);
        //搜索分词
        $sphinx = new SphinxClient();
        $sphinx->SetServer(C('SPHINX_HOST'), C('SPHINX_PORT'));
        $sphinx->SetArrayResult(true);
        $sphinx->SetLimits(0, C('SPHINX_MAXS'), C('SPHINX_MAXS'));
        $sphinx->SetMaxQueryTime(C('SPHINX_TIMES'));
        $index = 'test1' ;//索引源是配置文件中的 index 类，如果有多个索引源可使用,号隔开：'email,diary' 或者使用'*'号代表全部索引源
        $mode = 2;
        $sphinx->SetMatchMode($mode);

        switch($sort){
            case 'new'  :$order=array('goods_publish_time'=>'desc');
                break;
            case 'hot'  :$order=array('sg_goods_click'=>'desc');
                break;
            case 'sale' :$order=array('sg_goods_salenum'=>'desc');
                break;
            case 'price':$order=array('sg_goods_price'=>'desc');
                break;
            default     :$order=array('goods_publish_time'=>'desc');
                break;
        }

        $bids=array(); //已选品牌
        $bidsNot=array(); //未选品牌
        if(!array_is_empty($bid))
        {
            $where['sg_goods_brand_id']  =array('IN', $bid);
            $bids ['brand_id']           =array('IN',$bid);
            $bidsNot ['brand_id']        =array('NOT IN',$bid);
        }
        else
        {
            $bids ['brand_id']           =array('IN',[0]);
        }

        if(count($price)>1){
            $price[0] = intval($price[0]) ? intval($price[0]) : 0;
            $price[1] = intval($price[1]) ? intval($price[1]) : 9999;
            $where['sg_goods_price'] =array( 'BETWEEN', $price);
        }

        if($addTime)
        {
            $endTime = $addTime + 86400;
            $where['sg_goods_add_time'] = array( 'BETWEEN', array($addTime,$endTime));
        }

        if ($goodsPsn){
            // $goodsNo = substr($goodsPsn, 0,10);
            $pro = M('goods_pro') -> field('pro_goods_id') -> where('pro_sn like "%'.$goodsPsn.'%"') -> select();
            if($pro){
                $where['sg_goods_id'] = array('IN',array_column($pro,'pro_goods_id'));
            }
        }
        $this->assign('price',$price);//价格区间
        // 关键字搜索
        if(!$where){
                $k = $_GET['k'] = trim($k) ? $k : C('SEARCH_SHOP');
                if($k){
                    $add_keywords = array();
                    //把特定关键字排除分词
                    foreach(C('ADD_KEYWORDS') as $v){
                        if(stristr($k,$v)){
                            array_push($add_keywords,$v);
                            $k = str_replace($v,"",$k);
                        }
                    }
                    //得到分词
                    $postData = 'data='.$k.'&respond=json&charset=utf8&ignore=yes&duality=no&traditional=no&multi=0';
                    $words_arrays = array();
                    $words_array  =  DataApi::postFormData(C('SCWSAPI_ADDR'),$postData);
                    $words_array = json_decode($words_array, true);
                    if($words_array['status']=='ok'){
                        //echo '<pre>';
                        //print_r($words_array['words']);
                        foreach($words_array['words'] as $k => $v){
                            $sphinx->AddQuery($v['word'], $index);
                        }
                    }
                    if(!empty($add_keywords)){
                       foreach($add_keywords as $keyword){
                           $sphinx->AddQuery($keyword, $index);
                       }
                    }
                    $result = $sphinx->RunQueries ();
                    //处理数据库查询结果
                    $arr = array();
                    //关键字个数
                    $match_nums = count($result);
                    foreach($result as $k => $v){
                        if($v['total']>0){
                            foreach( $v['matches'] as $key=>$value) {
                                $arr[$k][] = $value['id'];
                            }
                        }
                    }
                    //小于4或4个关键字全部匹配
                    if($match_nums<=4){
                        if (!empty($arr)){
                            $arr2 = $arr['0'];
                            foreach ($arr as $value3){
                                $arr2 = array_intersect($arr2,$value3);
                            }
                        }else{
                            $arr2 = array();
                        }
                    }else{
                        $match_array = array();
                        $arr2 = array();
                        if (!empty($arr)){
                            foreach ($arr as $value3){
                                foreach($value3 as $value4){
                                    array_push($match_array,$value4);
                                }
                            }
                            //得到出现大于等于4次的good_id
                            foreach(array_count_values($match_array) as $m => $n){
                                if($n>3){
                                    array_push($arr2,$m);
                                };
                            }
                        }else{
                            $arr2 = array();
                        }
                    }
                    if (empty($arr2)){
                        array_push($arr2,0);
                    }
                    $where['sg_goods_id'] = array('in',$arr2);
        }}
            //总数量
            $goodsCount= M('shopGoods')->field('sg_goods_id')->where($where)->count('sg_goods_id');
            $Page       = new \Think\Page($goodsCount, 20);
            $show       = $Page->show();
            $shopGoods  = M('shopGoods')->join('dgc_goods ON sg_goods_id=goods_id','LEFT')
                ->field('sg_goods_id, sg_goods_shop_id, sg_goods_name, sg_goods_price,sg_goods_shop_id,sg_goods_shop_name,sg_goods_img,goods_publish_time')
                ->where($where)->order($order)->limit($Page->firstRow.','.$Page->listRows)->select();
            foreach ($shopGoods as $key => &$value) {
                $value['sg_goods_img'] = getImgUrl($value['sg_goods_img'],'m');
            }
            // echo M('shopGoods') -> getLastsql();
            //右上角分页设置
            $Page2       = new \Think\Page($goodsCount, 20);
            $Page2->rollPage = 1;
            $Page2->setConfig('theme','<b class="ui-page-s-len mr5"><span class="js_pageNow">%LINK_PAGE%</span>/<span class="js_pageCount">%TOTAL_PAGE%</span></b>%UP_PAGE% %DOWN_PAGE%');
            $Page2->setConfig('prev','<div class="ui-page-s-next js_pageNext">&lt;</div>');
            $Page2->setConfig('next','<div class="ui-page-s-next js_pageNext">&gt;</div>');
            $show2 = $Page2->show();

            $this->assign('goodsTotal',$goodsCount);
            $this->assign('goods',$shopGoods);// 赋值数据集
            $this->assign('page',$show);// 赋值分页输出
            $this->assign('page2',$show2);// 赋值分页输出

            //已选择品牌
            $brand = M('brand')->where($bids)->order('brand_order_master asc')->select();
            $this->assign('brand',$brand);

            //未选择品牌
            $brandNot = M('brand')->where($bidsNot)->order('brand_order_master asc')->select();
            $this->assign('brandNot',$brandNot);

            R('Index/hotWord'); 	//R跨控制器调用 index -> hotWord
            $this->cate();
            $this->nav();
            $this->display();
    }

    public function newGoods(){
        $type = I('get.t');
        $addTime = I('get.time');

        $where = array();
        $addTime = $_GET['time'] = $addTime ? $addTime : time();
        $endTime = $addTime + 86400;
        $where['sg_goods_add_time'] = array( 'BETWEEN', array($addTime,$endTime));
        if($type == 'n'){
            $where['sg_goods_cid1'] = array('EQ',4);// 女装/女士精品
        }
        if($type == 's'){
            $where['sg_goods_cid1'] = array('EQ',7);// 童装/婴儿装/亲子装
        }

        //总数量
        $goodsCount = M('shop_goods') ->field('sg_goods_id') -> where($where) -> count('sg_goods_id');
        $Page       = new \Think\Page($goodsCount, 20);
        $show       = $Page -> show();
        $shopGoods  = M('shop_goods')
            ->field('sg_goods_id, sg_goods_name, sg_goods_price,sg_goods_market_price,sg_goods_shop_id,sg_goods_shop_name,sg_goods_img')
            ->where($where)->order($order)->limit($Page->firstRow.','.$Page->listRows)->select();
        foreach ($shopGoods as $key => &$value) {
            $value['sg_goods_img'] = getImgUrl($value['sg_goods_img'],'m');
        }
        // echo M('shop_goods') -> getLastsql();

        $this -> assign('addTime',date('m月d号',$addTime));
        $this -> assign('goods',$shopGoods);// 赋值数据集
        $this -> assign('page',$show); // 赋值分页输出
        R('Index/hotWord');     //R跨控制器调用 index -> hotWord
        $this -> cate();
        $this -> nav();
        $this -> display();
    }


    //组货查询
    public function groupList()
    {
        $id = I('get.id');
        $goodsList = M('goodsGroupList')->alias('gg')->join('dgc_goods g ON gg.ggl_goods_id = g.goods_id')
            ->join('dgc_shop_info si ON g.goods_shop_id = si.shop_id')->where('ggl_gg_id = '.$id.' AND gg.ggl_state = 1')
            ->order('gg.ggl_sort')->select();
        $this->assign('goods',$goodsList);
        $this->cate();
        $this->nav();
        $this->display();
    }

    //店铺商品查询
    public function store()
    {
        $sid    = I('get.sid', '', 'int');          //店铺id
        $scid   = I('get.scid', '', 'int');         //店铺分类id
        $search = I('get.k');                       //搜索商品
        $category = array();
        $where = array();

        //分类栏目
        if($sid)
        {
            $where['sg_goods_shop_id'] = ['EQ' ,$sid];
            $shopCate = $this->getCategory($sid);
            $this->assign('shopCate',$shopCate);

            //导航
            $navList = $this -> getNavlist($sid);
            $this -> assign('navList', $navList);

            $hotGoods=M('shop_goods')->field('sg_goods_id as goods_id,sg_goods_name as goods_name,sg_goods_img as goods_img ,sg_goods_price as goods_price')
                ->where("sg_goods_shop_id = {$sid}")->order('sg_goods_salenum desc')
                ->limit(10)->select();
            $this->assign('hotGoods',$hotGoods);
        }

        if($search)
        {
            $where['sg_goods_name'] = ['like', "%{$search}%"];
        }

        if($scid)
        {
            $category['scate_id'] = ['EQ',$scid];
            $cate=M('shopCategoryGoods')->field('goods_id')->where($category)->select();
            if ($cate) {
                foreach($cate as $value){
                    $goodsId[] = $value['goods_id'];
                }
                $where['sg_goods_id']= array('IN',$goodsId);
            } else {
                $where['sg_goods_id'] = 0;
            }
        }

        //总数量
        $goodsCount= M('shop_goods')->field('sg_goods_id')->where($where)->count('sg_goods_id');

        $Page       = new \Think\Page($goodsCount, 20);
        $show       = $Page->show();
        $shopGoods=M('shop_goods')->field('sg_goods_id as goods_id,sg_goods_name as goods_name,sg_goods_img as goods_img ,sg_goods_price as goods_price')->where($where)->order('sg_goods_add_time desc')->limit($Page->firstRow.','.$Page->listRows)->select();
        $recomGoods = M('goods') -> field('goods_id, goods_name, goods_img, goods_price')
            -> where("goods_shop_id = {$sid} AND goods_is_recom = 1 AND goods_state = 3")
            -> order('goods_publish_time desc') -> limit(4) -> select();
        $shopInfo = M('shopInfo')->where('shop_id ='.$sid)->find();
        $this->assign('shopInfo',$shopInfo);
        $this->assign('recomGoods',$recomGoods);
        $this -> assign('shopGoods', $shopGoods);
        $this->assign('page',$show);// 赋值分页输出
        $this->assign('shopId',$shopInfo['shop_id']);
        //关注店铺
        $userLogin =$this->user_getUser();
        if($userLogin)
        {
            //判断用户使用有关注店铺
            $map = array();
            $map['fs_user_id'] = $userLogin['userId'];
            $map['fs_shop_id'] = $shopInfo['shop_id'];
            $followId = M('follow_shop')->where($map)->find();
            $this->assign('followId',$followId);
            $this->assign('userInfo',$userLogin);
        }
        $this->display();
    }
    //自动函数
    public function _auto()
    {

    }
}