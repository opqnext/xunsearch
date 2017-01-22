<?php
/**
 * 讯搜接口
 * User: 郭贰小姐
 * Date: 2016/12/22
 * Time: 17:35
 */

namespace core\search;

class XSearch
{
    public $xs;
    private $search;
    private $sortType;
    private $facet_num=100;

    private static $_instance;

    public function __construct($object,$sortType=array())
    {
        $this->xs = $object;    //建立XS对象
        $this->search = $this->xs->search;  //获取搜索对象
        $this->sortType = $sortType;    //设置可排序的字段
    }

    //单例方法,用于访问实例的公共的静态方法
    public static function getInstance($object,$sortType=array())
    {
        if (!(self::$_instance instanceof XSearch)) {
            self::$_instance = new self($object,$sortType);
        }
        return self::$_instance;
    }

    /**
     * @desc 在文章标题、文章标签、文章描述中模糊搜索
     *
     * @param string $keywords 搜索关键字，多条件并且用AND，或者用OR，异或用XOR
     * @param int $offset 从第几条开始(跳过前$offset条)
     * @param int $limit 返回结果条数
     * @param array $sort 按 filed 字段的值排序,false 为倒序排列,true为正序。
     * @param array $range 设置区间条件过滤
     * @param array $facet 设置分面搜索
     * @return array ["errcode"=>0,"errmsg"=>"","data"=>[],"count"=>7526]
     *
     * @example
     * $client = new Yar_Client("http://domain/search/goods");
     * $client->SetOpt(YAR_OPT_CONNECT_TIMEOUT, 10000);
     * $result = $client->search("迅搜安装",0,10);
     * var_dump($result);
     */
    public function search($keywords="", $offset = 0, $limit = 200, $sort = array(), $range = array(),$facet=array())
    {
        $res = $this->getRes();
        $keywords = $this->removeSpecStr($keywords); //去除搜索词的特殊字符
        //$this->search->setFuzzy()->setQuery($keywords); //设置搜索语句  开启模糊搜索
        $this->search->setQuery($keywords); //设置搜索语句    关闭模糊搜索
        $this->search->setLimit($limit, $offset); //设置分页和数量
        if (!empty($sort) && is_array($sort)) {
            $rule = true;
            if(!empty($this->sortType) && is_array($this->sortType)){  //如果可排序不为空才检测字段
                foreach ($sort as $k => $v) {
                    if (!$this->checkField($k)) {
                        $rule = false;
                        break;
                    }
                }
            }

            if ($rule) {
                $this->search->setMultiSort($sort);  //多条件排序
            }
        }
        if (!empty($range) && is_array($range)) {
            $this->search->addRange($range['field'], $range['from'], $range['to']);
        }
        if (!empty($facet) && is_array($facet)) {
            $this->search->setFacets($facet,true);
        }
        $data = $this->search->addWeight('tags',$keywords )->search(); //执行搜索，将搜索结果文档保存在 $data 数组中
        foreach ($data as $key => $val) {
            $val->_data['title'] = $this->search->highlight($val->_data['title']);
            $val->_data['description'] = $this->search->highlight($val->_data['description']);
            //友好的时间提醒 (这里格式化文章添加时间)
            $val->_data['add_time'] = M()->friendlyDate($val->_data['add_time']);
            //array_pop($val->_data);
            $res['data'][] = $val->_data;
        }
        $res['count'] = $this->search->lastCount; //查询结果数量
        if (!empty($facet) && is_array($facet)) {
            foreach($facet as $value){
                $res[$value]=$this->search->getFacets($value);
                arsort($res[$value]);
                if(count($res[$value])>$this->facet_num){
                    $res[$value] = array_slice($res[$value], 0, $this->facet_num,true);
                }
            }
        }

        //$res['relate'] = $this->search->getRelatedQuery(); //获取相关搜索

        return $res;
    }

    /**
     * 讯搜——添加/更新接口
     * For example:
     * $xsearch->update($data);
     *
     * @param array $data $key对应索引中的字段 $val为要更新的值
     * @return array
     */
    public function update($data)
    {
        $res = $this->getRes();
        $doc = new XSDocument;  // 创建文档对象
        $doc->setFields($data);
        $index = $this->xs->index; // 获取 索引对象

        $index->update($doc);   // 更新到索引数据库中
        return $res;
    }

    /**
     * 讯搜——添加/添加接口
     * For example:
     * $xsearch->add($data);
     *
     * @param array $data $key对应索引中的字段 $val为要更新的值
     * @return array
     */

    public function add($data)
    {
        $res = $this->getRes();
        $doc = new XSDocument;  // 创建文档对象
        $doc->setFields($data);
        $index = $this->xs->index; // 获取 索引对象

        $index->add($doc);  // 添加到索引数据库中
        return $res;
    }

    /**
     * @desc 查看数据总量
     *
     * @return array ["errcode"=>0,"errmsg"=>"","data"=>["total"=>499812]]
     *
     * @example
     * $client = new Yar_Client("http://domain/search/goods");
     * $result = $client->getTotal();
     * var_dump($result);
     */
    public function getTotal()
    {
        $res = $this->getRes();
        $total = $this->search->getDbTotal();
        $res['data'] = array("total" => $total);
        return $res;
    }

    /**
     * 讯搜——删除接口
     * For example:
     * $xsearch->del(array(10314));    删除一条记录
     * $xsearch->del(array(10314,10315,10316,10317));    删除多条记录
     *
     * @param array $id
     * @return array ["errcode"=>0,"errmsg"=>"","data"=>[]]
     */
    public function del($id)
    {
        $res = $this->getRes();
        $index = $this->xs->index; // 获取 索引对象
        $index->del($id);  // 删除主键值为 123 的记录
        return $res;
    }

    /**
     * 讯搜——获取热门搜索词
     * @param int $limit 整数值，设置要返回的词数量上限，默认为 6，最大值为 50
     * @param string $type 指定排序类型，默认为 total(总量)，可选值还有：lastnum(上周) 和 currnum(本周)
     * @return mixed
     */
    public function hot($limit = 10, $type = 'total')
    {
        $res = $this->getRes();
        $search = $this->xs->search; // 获取 搜索对象
        $hot = $search->getHotQuery($limit, $type);
        $res['data'] = $hot;
        return $res;
    }

    /**
     * 返回码格式
     * @return array
     */
    private function getRes()
    {
        $defaultRes = array(
            "errcode" => 0,
            "errmsg" => "",
            "data" => array()
        );
        return $defaultRes;
    }

    /**
     * 去除搜索词的特殊字符
     * @param $str
     * @return mixed|string
     */
    private function removeSpecStr($str)
    {
        $str = preg_replace('/\s+/', ' ', $str);
        $str = mb_convert_encoding(strip_tags($str), "UTF-8", "auto");
        $str = str_replace("\\","",$str);
        return $str;
    }

    /**
     * 检测排序字段是否正确
     * @param $filed
     * @return bool
     */
    private function checkField($filed)
    {
        return in_array($filed, $this->sortType);
    }
}