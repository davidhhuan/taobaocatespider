<?php
include_once dirname(__FILE__) . '/Curl.php';

/**
 * Class Spider
 *
 * @author birdylee <birdylee_cn@163.com>
 * @since 2018.10.07
 */
class Spider
{
    private $config;

    private $curl;

    /**
     * 执行
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    public static function run()
    {
        $obj = new static();
        $obj->config = include dirname(__FILE__)  . '/config.php';

        $obj->curl = new Curl();
        $obj->curl->options = [
            'ENCODING' => 'gzip', 
        ];

        $obj->createCidSelect($obj->config['cid_str']);
    }

    /**
     * @param $data
     * @param int $logType
     * ```
     * -----|-----
     * 0    |  debug
     * 1    |  info
     * 2    |  error
     * ```
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function log($data, $logType = 0)
    {
        $logFile = dirname(__FILE__) . '/' . $this->config['log_file'];
        $logTypeList = [
            0 => 'debug',
            1 => 'info',
            2 => 'error',
        ];

        $logTypeStr = isset($logTypeList[$logType]) ? $logTypeList[$logType] : $logType[0];
        $data = !is_string($data) ? var_export($data, true) : $data;

        $str = "\n" . date("Y-m-d H:i:s") . ' ' . microtime(true) . ' ' . '['.$logTypeStr.']'
            . "\n" . $data;
        file_put_contents($logFile, $str, FILE_APPEND);
    }

    /**
     * 取出类目
     *
     * @param $cidStr
     * @return bool
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function createCidSelect($cidStr)
    {
        list($cid, $cidListStr, $spanId) = explode("|", $cidStr);
        if ($cid === '') {
            return false;
        }
        $cidArr = json_decode($cidListStr, true);
        if (empty($cidArr) || empty($cidArr['itemcats_get_response']['item_cats']['item_cat'])) {
            return false;
        }
        $cidArr = $cidArr['itemcats_get_response']['item_cats']['item_cat'];
        $parentId = $cid;
        foreach($cidArr as $keyItem => $valueItem) {
            $valueItem['is_parent'] = empty($valueItem['is_parent']) ? 0 : 1;
            $valueItem['status'] = $valueItem['status'] == 'normal' ? 1 : 0;
            $this->saveCidSql($valueItem);
            $this->childCidList($valueItem, $parentId);
        }
    }

    /**
     * 获取子类目
     *
     * @param $item
     * @param $parentId
     * @return bool
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function childCidList($item, $parentId) 
    {
        $cid = $item['cid'];
        if ($item['is_parent'] == 0) {
            return $this->loadScript($cid);
        }
        try {
            $url = str_replace('XXXXXXXXXXXXXXXXXXXX', $cid, $this->config['cate_child_request_params']['url']);
            $this->curl->headers = $this->config['cate_child_request_params']['headers'];
            $res = $this->curl->get($url);
            if (!empty($this->curl->error())) {
                $this->log($this->curl->error(), 2);
                return false;
            } else {
                $cidStr = $cid . '|' . $res['body'] . '|' . $parentId;
                $this->createCidSelect($cidStr);
            }
        } catch (\Exception $e) {
            $this->log($e->getMessage(), 2);
        }

        sleep($this->config['sleep_second']);
    }

    /**
     * 加载prop跟prop value
     *
     * @param $cid
     * @return bool
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function loadScript($cid)
    {
        try {
            $url = str_replace('XXXXXXXXXXXXXXXXXXXX', $cid, $this->config['script_request_params']['url']);
            $this->curl->headers = $this->config['script_request_params']['headers'];
            $res = $this->curl->get($url);
            if (!empty($this->curl->error())) {
                $this->log($this->curl->error(), 2);
                return false;
            } else {
                $outArr = explode(';', $res['body']);
                if (count($outArr) == 3) {
                    if ($outArr[0] != '' 
                        && strpos($outArr[0], 'var props={"itemprops_get_response":{"item_props":{"item_prop":') !==false ) {
                        $propsStr = trim(str_replace('var props=', '', $outArr[0]));
                        $propsArr = json_decode($propsStr, true);
                        if (isset($propsArr['itemprops_get_response']['item_props']['item_prop']) 
                            && is_array($propsArr['itemprops_get_response']['item_props']['item_prop'])) {
                            foreach ($propsArr['itemprops_get_response']['item_props']['item_prop'] as $keyProp => $valueProp) {
                                $this->savePropSql($valueProp);
                            }
                        }
                    } else {
                        $this->log($outArr[0], 2);
                    }

                    if ($outArr[1] != '' 
                        && strpos($outArr[1], 'var propvalues={"itempropvalues_get_response":{"last_modified":') !==false ) {
                        $propValuesStr = trim(str_replace('var propvalues=', '', $outArr[1]));
                        $propValuesArr = json_decode($propValuesStr, true);
                        if (isset($propValuesArr['itempropvalues_get_response']['prop_values']['prop_value']) 
                            && is_array($propValuesArr['itempropvalues_get_response']['prop_values']['prop_value'])) {
                            foreach ($propValuesArr['itempropvalues_get_response']['prop_values']['prop_value'] as $keyPropValue => $valuePropValue) {
                                $this->savePropValueSql($valuePropValue);
                            }
                        }
                    } else {
                        $this->log($outArr[1], 2);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log($e->getMessage(), 2);
        }

        sleep($this->config['sleep_second']);
    }

    /**
     * @param $item
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function saveCidSql($item)
    {
        $this->saveSql($item, $this->config['cid_table_name'], $this->config['cid_sql_file']);
    }

    /**
     * @param $item
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function savePropSql($item)
    {
        $this->saveSql($item, $this->config['prop_table_name'], $this->config['prop_sql_file']);
    }

    /**
     * @param $item
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function savePropValueSql($item)
    {
        $this->saveSql($item, $this->config['prop_value_table_name'], $this->config['prop_value_sql_file']);
    }

    /**
     * @param $item
     * @param $tableName
     * @param $filePath
     *
     * @author birdylee <birdylee_cn@163.com>
     * @since 2018.10.07
     */
    protected function saveSql($item, $tableName, $filePath)
    {
        $itemKey = array_keys($item);
        $fieldKeyArray = [];
        foreach ($itemKey as $key => $value) {
            $fieldKeyArray[] = '`' . $value . '`';
        }

        $itemValue = array_values($item);
        $fieldValueArray = [];
        foreach ($itemValue as $key => $value) {
            $fieldValueArray[] = "'" . $value . "'";
        }

        $sql = 'INSERT INTO ' . $tableName
            . '(' . implode(', ', $fieldKeyArray) . ')' 
            . ' VALUES ' 
            . '(' . implode(', ' , $fieldValueArray) . ')'
            . ';';

        $allSql = file_get_contents(dirname(__FILE__) . '/' . $filePath);
        if (strpos($allSql, $sql) === false) {
            file_put_contents(dirname(__FILE__) . '/' . $filePath, $sql . "\n", FILE_APPEND);
        }
    }
}