<?php

namespace Irmyang\Database;


class Base
{
    protected $data_is_add=true;//允许添加
    protected $data_is_edit=true;//允许修改
    protected $data_field_edit=true;//允许修改字段
    protected $data_is_delete=true;//允许删除
    /**
     * @var string
     */
    protected $connection = '';
    /**
     * 存在上级字段设置为true
     *
     * @var string
     */
    protected $is_sort = false;
    /**
     * 排序字段
     *
     * @var string
     */
    protected $sort_key = 'sort';
    /**
     * 存在上级字段设置为true
     *
     * @var string
     */
    protected $is_parent = false;
    protected $is_view = false;
    /**
     * 存在上级字段
     *
     * @var string
     */
    protected $parent_key = 'parent_id';
    /**
     * 存在顶级字段
     *
     * @var string
     */
    protected $parent_top_key = '';
    /**
     * 设置删除状态
     *
     * @var string
     */
    protected $is_delete =false;
    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The storage format of the model's date columns.
     *
     * @var string
     */
    protected $dateFormat = 'U';
    /** @title 获取表字段控制器
     *
     * @return   object
     */
    function getTableFieldModel()
    {
        $model=new TableField;
        $connection=$this->getConnectionName();
        $model->setConnection($connection);
        return $model;
    }
    /** @title 检测表是否存在
     *
     * @param     string  $table  表
     * @return    boolean|array
     */
    function getTableInfo($is_update=false)
    {
        $key='field-list-'.$this->table;
        if(!$is_update)
        {
            $data=Cache::get($key);
            if($data) return $data;
        }
        $data=[];
        $field_model=$this->getTableFieldModel();
        $data['lists']=$field_model->getFieldList($this);
        if(!$data['lists'])
        {
            $this->setAttributes('msg',$field_model->msg);
            return false;
        }
        $data['modular_file']=[];
        foreach($data['lists'] as $k=>$v){
            if($v->admin_list_fold) $data['is_list_fold']=true;
            if($v->admin_list_custom) $data['is_list_custom']=true;
            if($v->admin_footer_edit) $data['is_footer_edit']=true;
            if($v->field_type=='modular-file') $data['modular_file'][]=$k;
        }

        //有上级排序
        if($this->is_parent)
        {
            $data['is_parent']=true;
            $data['parent_key']=$this->parent_key;
            $data['expand_show']=true;//折叠展示二级内容
        }

        if($this->is_view) $data['is_view']=true;

        //字段编辑
        $data['data_field_edit']=$this->data_field_edit;
        $data['data_is_add']=$this->data_is_add;
        $data['data_is_edit']=$this->data_is_edit;
        $data['data_is_delete']=$this->data_is_delete;

        Cache::set($key,$data);
        return $data;
    }
    /** @title 添加额外字段
     *
     * @param    array  param  查询字段
     * @return   array
     */
    function addExtrFields($item=[])
    {
        return $item;
    }
    /** @title 保存数据
     *
     * @param    array  data  保存的数据
     * @param    boolean  safe_check 安全验证
     * @return   object|boolean
     */
    function setSave($data)
    {
        //防止层级结构问题
        if($this->is_parent)
        {
            if($this->parent_key!=='parent_id' && isset($data['parent_id']))
            {
                $data[$this->parent_key]=$data['parent_id'];
            }
        }
        if(isset($data['status']) && empty($data['status'])) $data['status']=1;
        //自动添加排序
        if($this->is_sort) $data[$this->sort_key]=$this->getSortValue($data);

        $model=clone $this;
        $num=0;
        $table_config=$this->getTableInfo(false,$this);
        foreach($table_config['lists'] as $v)
        {
            //禁止修改字段
            if(!$v->admin_edit) continue;
            $field=$v->field;
            //未修改数据
            if(!isset($data[$field])) continue;
            //禁止为空
            if(!$v->null && empty($data[$field]))
            {
                $this->setAttributes('msg',$v->comment.'禁止为空');
                return false;
            }
            $model->$field=$data[$field];
            $num++;
        }
        if(!$num)
        {
            $this->setAttributes('msg','无符合条件数据');
            return false;
        }
        $model->save();
        return $model;
    }
    /** @title 批量添加
     *
     * @param     string  $table  表
     * @return    boolean
     */
    function setSaveMore($lists=[])
    {
        //自动添加排序
        if($this->is_sort)
        {
            $sort=0;
            foreach($lists as $k=>$v)
            {
                $sort=$this->getSortValue($v,$sort);
                $v[$this->sort_key]=$sort;
                $lists[$k]=$v;
            }
        }

        $val_k='`'.implode('`,`',array_keys($lists[0])).'`';
        $val_v='';
        foreach($lists as $k=>$v)
        {
            $val_v.=($val_v ? ',':'').'("'.implode('","',array_values($v)).'")';
        }

        $model=$this->getConnection();
        $table=$model->getTablePrefix() . $this->getTable();
        $query='insert into '.$table.' ('.$val_k.') VALUES '.$val_v;
        return $model->statement($query);
    }
    /** @title 获取排序值
     *
     * @param    array data 保存的数据
     * @return   int
     */
    function getSortValue($data,$sort=0)
    {
        if($sort) return $sort+1;

        $sort_key=$this->sort_key;
        $item=(clone $this)->select($this->primaryKey,$sort_key);
        if($this->parent_top_key && isset($data[$this->parent_top_key]))
        {
            $item=$item->where($this->parent_top_key,$data[$this->parent_top_key]);
        }
        if(isset($data[$this->parent_key]))
        {
            $item=$item->where($this->parent_key,$data[$this->parent_key]);
        }
        $item=$item->orderBy($sort_key,'DESC')->first();
        return $item ? $item->$sort_key + 1 : 1;
    }

    /** @title 移动排序
     *
     * @param   int id 内容ID
     * @param   bool type 移动类型 false 上移 true 下移
     * @return   array|boolean
     */
    function moveSort($id,$type=false)
    {
        $sort_key=$this->sort_key;
        $parent_key=$this->parent_key;
        $parent_top_key=$this->parent_top_key;

        $field=['id',$sort_key];
        $sql=[];
        $sql['where'][]=[$this->primaryKey,$id];
        $sql['field']=[$this->primaryKey,$sort_key];

        if($parent_top_key) $field[]=$parent_top_key;
        if($this->is_parent) $field[]=$parent_key;
        $one=clone $this;
        $one=$one->select($field)->where('id',$id)->first();
        if(!$one) return false;

        $tow=clone $this;
        $tow=$tow->select($field);
        $sql=[];
        $sql['field']=[$this->primaryKey,$sort_key];
        if($parent_top_key) $tow=$tow->where($parent_top_key,$one->$parent_top_key);
        if($this->is_parent) $tow=$tow->where($parent_key,$one->$parent_key);
        if($type)
        {
            $tow=$tow->where($sort_key,'>',$one->$sort_key)->orderBy($sort_key,'ASC');
        }
        else
        {
            $tow=$tow->where($sort_key,'<',$one->$sort_key)->orderBy($sort_key,'DESC');
        }
        $tow=$tow->first();
        if(!$tow)
        {
            if($type)
            {
                $this->setAttributes('msg','已经排在最后面！');
                return false;
            }
            else
            {
                $this->setAttributes('msg','已经排在最前面！');
                return false;
            }
        }
        $sort=$one->$sort_key;
        $one->$sort_key=$tow->$sort_key;
        $tow->$sort_key=$sort;

        if(!$one->save())
        {
            $this->setAttributes('msg','保存排序失败！');
            return false;
        }
        if(!$tow->save())
        {
            $this->setAttributes('msg','保存排序失败！');
            return false;
        }
        return [$one,$tow];
    }

    /** @title 移动排序
     *
     * @param   array $data 请求参数
     * @return   object|array|boolean
     */
    function getDataList($param=[]){
        //获取可以查询的字段
        $table_info = $this->getTableInfo();
        if(!$table_info) return [];

        //相关模块
        $relevance_model=[];

        $field=[];
        $field[]=$this->primaryKey;
        $model =clone $this;
        //循环获取字段
        foreach ($table_info['lists'] as $k=>$v){
            //判断是否可以查询
            if($v->admin_search == 1)
            {
                //判断类型
                switch ($v->field_type){
                    case 'varchar'://字符串
                    case 'text'://文本
                        if(!empty($param[$v->field])) $model=$model->where($v->field,'like','%'.$param[$v->field].'%');
                        break;
                    case 'select'://选择
                        if(!empty($param[$v->field]))
                        {
                            $model=$model->where($v->field,$param[$v->field]);
                        }
                        else
                        {
                            //当为状态值的时候
                            if(!empty($v->status_search))
                            {
                                $model=$model->where($v->field,'>',0);
                            }
                        }
                        break;
                    case 'checkbox'://复选
                        if(!empty($param[$v->field]))
                        {
                            $checkbox_lists=explode(',',$param[$v->field]);
                            foreach($checkbox_lists as $checkbox_v)
                            {
                                $model=$model->whereRaw('FIND_IN_SET(?,`'.$v->field.'`)',$checkbox_v);
                            }
                        }
                        break;
                    case 'linkage'://联动模型
                        $eliminate_key=$v->field.'_eliminate';
                        if(isset($param[$v->field]))
                        {
                            $db=new LinkageAttribute;
                            if($v->is_multiple)
                            {
                                $str=$param[$v->field];
                                if(!empty($param[$eliminate_key]))
                                {
                                    $str.=' '.$param[$eliminate_key];
                                }
                                $model=$model->whereRaw('match('.$v->field.') AGAINST(\''.$str.'\' IN BOOLEAN MODE)');
                            }
                            else
                            {
                                $linkage_ids=$db->getSonByType($v->linkage_field,$param[$v->field]);
                                $model=$model->whereIn($v->field,$linkage_ids);
                            }
                        }
                        break;
                    case 'modular-file'://模块
                        if(isset($param[$v->field]))
                        {
                            if(empty($param[$v->field]))
                            {
                                if(empty($param[$v->field.'_all']))
                                {
                                    if($this->is_sort)
                                    {
                                        $model=$model->where($v->field,0);
                                    }
                                }
                            }
                            else
                            {
                                $model=$model->where($v->field,$param[$v->field]);
                            }
                        }
                        else
                        {
                            if($this->is_sort)
                            {
                                $model=$model->where($v->field,0);
                            }
                        }
                        break;
                    case 'modular-more'://模块多联
                        if(!empty($param[$v->field]))
                        {
                            $checkbox_lists=explode(',',$param[$v->field]);
                            foreach($checkbox_lists as $checkbox_v)
                            {
                                $model=$model->whereRaw('FIND_IN_SET(?,`'.$v->field.'`)',$checkbox_v);
                            }
                        }
                        break;
                    default:
                        if(empty($param[$v->field]))
                        {
                            if($v->field_type=='int' && $this->is_sort)
                            {
                                $model=$model->where($v->field,0);
                            }
                        }
                        else
                        {
                            $model=$model->where($v->field,$param[$v->field]);
                        }
                        break;
                }
            }
            //展示字段 列表 折叠
            if($v->admin_list_show == 1 || $v->admin_list_fold == 1 || $v->admin_list_custom == 1)
            {
                $field[]=$v->field;
                if($v->field_type=='modular-file')
                {
                    $item=['field_key'=>$v->field,'model'=>$v->model,'field'=>[$v->model_field],'ids'=>[]];
                    $relevance_model[]=$item;
                }
            }
        }

        if(isset($table_info['order_lists']))
        {
            foreach($table_info['order_lists'] as $v)
            {
                if(isset($param[$v['field']]))
                {
                    $k=$param[$v['field']] ? $param[$v['field']] : 0;
                    $model=$model->orderBy($v['value_lists'][$k]['key'],$v['value_lists'][$k]['type']);
                }
            }
        }
        //排序字段
        if($this->is_sort)
        {
            $sort_key=isset($this->sort_key) ? $this->sort_key : 'sort';
            $model=$model->orderBy($sort_key,'ASC');
        }

        //模糊搜素
        if($this->is_search_key && !empty($param['search_key']))
        {
            $terms_db=new Terms;
            $words=$terms_db->getSearchKey($param['search_key']);
            $model=$model->whereRaw('match(search_key) AGAINST(\''.$words.'\')');
            $model=$model->inRandomOrder();
        }

        $page_size=isset($param['page_size']) ?  $param['page_size'] : 20;

        $paginator=$model->paginate($page_size);
        $data=[];
        $data['total'] = $paginator->total();
        $data['lists'] = $paginator->items();
        if($relevance_model)
        {
            foreach($data['lists'] as $v)
            {
                foreach($relevance_model as $k2=>$v2)
                {
                    $field=$v2['field_key'];
                    if($v->$field) $relevance_model[$k2]['ids'][$v->$field]=$v->$field;
                }
            }
            foreach($relevance_model as $k=>$v)
            {
                if($v['ids'])
                {
                    $model=new $v['model'];
                    $key=$model->getProtectedValueByKey('primaryKey');
                    $v['field'][]=$key;
                    $_lists=$model->select($v['field'])->whereIn($model->primaryKey,$v['ids'])->get();
                    foreach($_lists as $k2=>$v2)
                    {
                        $v['ids'][$v2->$key]=$v2;
                    }
                    $relevance_model[$k]=$v;
                }
            }
            foreach($data['lists'] as $k=>$v)
            {
                foreach($relevance_model as $v2)
                {
                    $field=$v2['field_key'];
                    if(isset($v2['ids'][$v->$field]))
                    {
                        $v->setAttributes($field.'_item',$v2['ids'][$v->$field]);
                    }
                }
                $data['lists'][$k]=$v;
            }
        }
        if(count($data['lists'])<$param['page_size']) $data['is_over']=1;
        return $data;
    }
    /**
     * @title 获取创建表格字段
     * @return array
     */
    function getTableField(){
        return [];
    }
    /** @title 获取对象属性
     *
     * @Parameters string  key  键值
     * @return    boolean|string
     */
    function getProtectedValueByKey($key='')
    {
        return $this->$key;
    }
    /** @title 设置对象属性
     *
     * @Parameters string  key  键值
     * @return  null
     */
    function setProtectedValueByKey($key='',$value='')
    {
        $this->$key=$value;
    }
    /** @title 添加属性值
     *
     * @Parameters  string  $key 键值
     * @Parameters  string  $val  值
     * @return  null
     */
    function setAttributes($key,$val)
    {
        $this->attributes[$key]=$val;
        $this->original[$key]=$val;
    }
}
