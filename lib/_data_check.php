<?php
include_once 'api_sqlite.php';
include_once 'api_logger.php';
include_once 'api_ucrm.php';

class Checker
{
    private $request;
    private $attrs;
    private $devs;
    private $report;

    public function exec()
    {
        $action = $this->request()->action ?? 'none';
        if($action == 'devices'){
            $this->report = $this->db()->selectAllFromTable('devices');
            return [];
        }
       return $this->analyse();
    }

    public function result(){ return $this->report; }
    public function status(){ return new stdClass(); }

    public function analyse()
    {
        $db = new SQLite3('data/data.db');
        $db->exec("ATTACH 'data/cache.db' as ca");
        $db->exec('CREATE TEMP TABLE "uisp" ("id" INTEGER NOT NULL,status INTEGER,client_id INTEGER, PRIMARY KEY("id"))');
        $statuses = [];
        $did = $this->request()->data->id ?? 1;
        $offset = 0 ;
        while($services = $this->read_uisp($offset)){
            $ids = [];
            foreach($services as $item){
                $statuses[$item['status']] ??= [];
                $ids[] = sprintf("(%s,%s,%s)",$item['id'],$item['status'],$item['clientId']);
            }
            $IDS = implode(',',$ids);
            $db->exec("insert into uisp (id,status,client_id) values $IDS");
            $offset += 500;
        }
        $report = [];

        foreach ($statuses as $status => $items){
            $sts = [
                'written_invalid' => "select ifnull(count(id),0) from services where device = $did and status = $status and id not in (select id from uisp)",
                'written_total' => "select ifnull(count(id),0) from services where device = $did and status=$status",
                'written_missing' => "select ifnull(count(id),0) from uisp where status = $status and id not in (select id from services where status = $status and device = $did)",
                'cached_invalid' => "select ifnull(count(id),0) from ca.services where device = $did and status = $status and id not in (select id from uisp)",
                'cached_total' => "select ifnull(count(id),0) from ca.services where device = $did and status=$status",
                'cached_missing' => "select ifnull(count(id),0) from uisp where status = $status and id not in (select id from ca.services where status = $status and device = $did)",
                'status_items' => "select ifnull(count(id),0) from uisp where status = $status",
                'total_items' => "select ifnull(count(id),0) from uisp",
            ];
            foreach ($sts as $key => $sql){
                $report[$status][$key] = $db->querySingle($sql);
            }
        }
        foreach ($statuses as $status => $items){
            $sts = [
                'written_invalid_items' => "select 'client: ' || clientId || ' service: ' || id as service from services where device = $did and status = $status and id not in (select id from uisp)",
                'written_missing_items' => "select 'client: ' || client_id || ' service: ' || id as service from uisp where status = $status and id not in (select id from services)",
                'cached_invalid_items' => "select 'client: ' || clientId || ' service: ' || id as service from ca.services where device = $did and status = $status and id not in (select id from uisp)",
                'cached_missing_items' => "select 'client: ' || client_id || ' service: ' || id as service from uisp where status = $status and id not in (select id from ca.services)",
            ];
            foreach($sts as $key => $sql){
                $read = $db->query($sql);
                while ($item = $read->fetchArray(SQLITE3_ASSOC)){
                    $report[$status][$key][] = $item['service'] ;
                }
            }
        } MyLog()->Append($report);
        $this->report = $report;
        return $report;
    }

    private function read_uisp($offset = 0)
    {
        $attrs = $this->attrs();
        $key = $this->config()->device_name_attr ?? 'none';
        $device_attr = $attrs[$key] ?? null;
        $did = $this->request()->data->id ?? 0;
        $device = $this->devs()[$did];
        $opts = ['limit' => 500,'offset' => $offset,'statuses' => [0,1,3,4,6,7],'customAttributeId' => $device_attr['id'],
            'customAttributeValue' => $device['name']];
        return $this->ucrm()->get('clients/services',$opts);
    }

    private function devs()
    {
        if(isset($this->devs)){ return $this->devs; }
        $read = $this->db()->selectAllFromTable('devices');
        $map = [];
        foreach ($read as $item){ $map[$item['id']] = $item; }
        $this->devs = $map;
        return $this->devs;
    }

    private function attrs()
    {
        if(isset($this->attrs)){ return $this->attrs; }
        $read = $this->ucrm()->get('custom-attributes');
        $map = [];
        foreach ($read as $item){
            $map[$item['key']]= $item;
        }
        $this->attrs = $map;
        return $this->attrs ;
    }

    private function ucrm()
    {
        return new ApiUcrm(null,true);
    }

    private function config()
    {
        return $this->db()->readConfig();
    }

    private function db(){
        return mySqlite();
    }

    private function cdb()
    {
        return myCache();
    }

    private function request()
    {
        if(isset($this->request)){ return $this->request; }
        $json = file_get_contents('php://input');
        if(!$json && is_file('test.json')){
            $json = file_get_contents('test.json');
        }
        $obj = json_decode($json);
        $this->request = is_object($obj) ? $obj : new stdClass() ;
        return $this->request ;
    }
}