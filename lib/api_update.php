<?php
include_once 'api_common.php';
include_once 'database_backup.php';
class ApiUpdate
{
    private string $mode ;
    private $data;
    private $result;
    private bool $fast = false ;

    public function exec(): void
    {
        $allowed = 'services,config,devices,plans,jobs,backups,attributes,attrs,system';
        if(!in_array($this->mode,explode(',',$allowed))){
            fail('invalid_mode',$this->data);
        }
        $action = $this->data->action ?? null;
        $input = $this->data->data ?? null;
        if (is_object($input) && isset($input->id) && !filter_var($input->id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new InvalidArgumentException('Invalid ID');
        if ($this->mode === 'devices' && in_array($action, ['insert', 'edit'], true)) $this->validateDevice((array)$input);
        switch ($action){
            case 'retry':
                if ($this->mode !== 'jobs') throw new InvalidArgumentException('Invalid retry target');
                include_once 'job_store.php';
                (new JobStore())->retry((int)($this->data->data->id ?? 0));
                $this->result = [];
                break;
            case 'diagnostics':
                if ($this->mode !== 'devices') throw new InvalidArgumentException('Invalid diagnostics target');
                include_once 'router_diagnostics.php';
                $id = filter_var($this->data->data->id ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$id) throw new InvalidArgumentException('Invalid device ID');
                $this->result = RouterDiagnostics::probe($this->db()->selectDeviceById($id));
                break;
            case 'insert': $this->result = $this->insert(); break;
            case 'edit': $this->result = $this->edit(); break;
            case 'delete': $this->result = $this->delete(); break;
            case 'backup': $this->result = $this->backup(); break;
            case 'restore': $this->result =$this->restore(); break;
            case 'publish': $this->result =$this->publish(); break;
            case 'unpublish' : $this->result =$this->publish(true); break;
            case 'cache': $this->result =$this->cache_build(); break;
            case 'log_clear' : $this->result =$this->log_clear(); break;
            case 'test_radius': $this->result = $this->test_radius(); break;
            default: fail('invalid_action',$this->data);
        }
    }

    public function backup(): array
    {
        if(!in_array($this->mode,['backups','system'])){
            fail('invalid_mode',[$this->mode,$this->data]);
        }
        $last = $this->find_last_backup() ;
        $index = preg_replace("/\D/",'',$last);
        if(++$index > 6) $index = 0 ;
        $dir = 'data';
        $src = "$dir/data.db";
        $next = "$dir/backup-$index";
        if(is_file($src)){
            DatabaseBackup::snapshot($src, $next);
            MyLog()->Append(['backup_success',$next,date('c',filemtime($next))]);
            return [1];
        }
        fail('backup_fail',$this->data);
    }

    private function delete()
    {
        switch ($this->mode){
            case 'devices':
            case 'delete': return $this->delete_db();
            case 'services':
            case 'system': return $this->delete_services();
            case 'jobs': return $this->delete_jobs();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function edit()
    {
        switch ($this->mode){
            case 'devices':
            case 'plans': return $this->edit_db();
            case 'config': return $this->edit_config();
            case 'attrs':
            case 'attributes': return $this->edit_attr();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function edit_config()
    {
        $data = json_decode(json_encode($this->data->data),true);
        $conf = $this->db()->readConfig() ;
        foreach (['radius_pass', 'radius_secret'] as $secret) if (array_key_exists($secret, $data) && $data[$secret] === '') unset($data[$secret]);
        foreach (array_keys($data) as $k){ if(!property_exists($conf,$k)){
            fail('invalid_config_key',$this->data); }}
        if($this->db()->saveConfig($data)){
            MyLog()->Append('config_edit_success');
            return [];
        }
        fail('edit_config_fail',$this->data);
    }

    private function insert()
    {
        switch ($this->mode){
            case 'devices':
            case 'plans': return $this->insert_db();
            case 'services':
            case 'system': return $this->insert_services();
            case 'attrs':
            case 'attributes': return $this->insert_attr();
            default: fail('invalid_mode',[$this->mode,$this->data]);
        }
    }

    private function log_clear(): array
    {
        $fn = 'data/plugin.log';
        if(file_put_contents($fn,date('c') . " log_reset\n")){
            MyLog()->Append('log_clear_success');
            return [];
        }
        fail('log_clear_fail');
    }

    private function test_radius(): array
    {
        include_once 'radius_backend.php';
        $cfg = (array)($this->data->data ?? []);
        foreach (['radius_pass', 'radius_secret'] as $secret) if (empty($cfg[$secret])) $cfg[$secret] = $this->db()->readConfig()->$secret ?? '';
        return RadiusBackend::test_connection($cfg);
    }

    private function publish($clear = false): array
    {
        throw new RuntimeException('Public backup publishing is disabled; backups contain credentials.');

    }

    public function restore(): array
    {
        $dir = 'data';
        $fn = "$dir/" . DatabaseBackup::name((string)($this->data->data->name ?? ''));
        if(is_file($fn)){
            DatabaseBackup::restore($fn);
            MyLog()->Append(['restore_success',$fn,date('c',filemtime($fn))],6);
            return [];
        }
        fail('restore_fail',$this->data);
    }

    private function delete_db(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        $id = $data['id'] ?? null ;
        if($id){ $data = [$id]; }
        $pk = $this->find_pk();
        if(!$data || !$pk){ return null; }
        foreach ($data as $value) if (!filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new InvalidArgumentException('Invalid delete ID');
        $ids = implode(',',$data);
        $table = $this->mode ;
        $s = "select * from $table where $pk in ($ids)";
        $before = $this->db()->selectCustom($s);
        $d = "delete from $table where $pk in ($ids)";
        if($this->db()->exec($d)){
            $this->result = $before ;
            if($this->mode == 'devices'){ $this->cache_build(); }
            return $this->result ;
        }
        fail('db_delete_fail',$this->data);
    }

    private function delete_jobs(): array
    {
        include_once 'job_store.php';
        (new JobStore())->clear();
        return [];
    }

    private function delete_services(): array
    {
        $this->fast_finish();
        $ids = $this->find_services() ;
        if(!$ids){ return [] ; }
        $batch = new Batch();
        if($batch->del_accounts($ids)){
            MyLog()->Append(['delete_services_success','items: '.sizeof($ids)]);
            return [];
        }
        fail('delete_services_fail',$this->data);
    }

    private function insert_attr()
    {
        $an = $this->data->data->value ?? null;
        $key = $this->data->data->key ?? 'nokey' ;
        $conf = $this->db()->readConfig();
        if(!$an || !property_exists($conf,$key)){
            fail('insert_attr_fail',$this->data);
        }
        $data = [
            'name' => $an,
            'type' => 'string',
            'clientZoneVisible' => false,
            'attributeType' => 'service',
        ];
        $done = $this->ucrm()->post('custom-attributes',$data);
        $value = $done->key ?? null ;
        if($key && $this->db()->saveConfig([$key => $value])){
            MyLog()->Append(['insert_attr_success',$key,$value]);
            return [];
        }
        fail('insert_attr_fail',$data);
    }

    private function insert_db()
    {
        $data = json_decode(json_encode($this->data->data),true);
        $cols = $this->find_columns() ;
        $qos = $this->qos_change();
        if(!$data || !$cols){ fail('insert_invalid',$this->data); }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $now = date('c');
        $trim['last'] = $now ;
        $trim['created'] = $now ;
        if($this->db()->insert($trim,$this->mode)){
            $id = $data['id'] ?? null ;
            $this->result = $this->find_last($id);
            if($this->mode == 'devices')
            {
                $this->cache_build(true) && $this->set_qos($qos);
            }
            return $this->result;
        }
        fail('db_insert_fail',$this->data);
    }

    private function insert_services($type = null): array
    {
        $this->fast_finish();
        $ids = $this->find_services($type) ;
        if(!$ids){ return [] ; }
        $this->clean_services($type);
        $batch = new Batch();
        if($batch->set_accounts($ids)){
            MyLog()->Append(['insert_services_success','items: '.sizeof($ids)]);
            return [];
        }
        fail('insert_services_fail',$this->data);
    }

    private function clean_services($type = null)
    {
        $id = $this->data->data->id ?? 0 ;
        $type = $this->data->data->type ?? $type ?? 'device' ;
        if(in_array($type,['service','services'])){ return; }
        $sql = ["DELETE FROM services"];
        if($type == 'device'){ $sql[] = "WHERE device = $id";}
        $this->db()->exec(implode(' ',$sql));
    }

    private function edit_attr()
    {
        $key = $this->data->data->key ?? 'NoKey';
        $value = $this->data->data->value ?? null ;
        $conf = $this->db()->readConfig();
        if(property_exists($conf,$key)
            && $this->db()->saveConfig([$key => $value])){
            MyLog()->Append(['edit_attr_success',$key,$value]);
            return [];
        }
        fail('edit_attr_fail',$this->data);
    }

    private function edit_db(): ?array
    {
        $data = json_decode(json_encode($this->data->data),true);
        $pk = $this->find_pk();
        $id = $data[$pk] ?? null ;
        $qos = $this->qos_change() ;
        $cols = array_diff($this->find_columns(),['created']) ;
        if(!$data || !$pk || !$id || !$cols){
            fail('edit_invalid',$data);  }
        $fill = array_fill_keys($cols,null);
        $trim = array_intersect_key($data,$fill);
        $trim['last'] = date('c') ;
        if($this->db()->edit($trim,$this->mode)){
            $this->result = $this->find_last($trim[$pk]);
            switch ($this->mode){
                case 'devices': $this->cache_build(true) && $this->set_qos($qos); break;
                case 'plans': $this->insert_services('plans'); break ;
            }
            MyLog()->Append(['edit_db_success','mode: '. $this->mode,'id: '. $id]);
            return $this->result ;
        }
        fail('db_edit_fail',$this->data);
    }

    private function find_last($id = null)
    {
        $table = $this->mode ;
        $pk = $this->find_pk() ;
        if(!$id){
            $id = $this->db()->singleQuery("select max($pk) from $table");
        }
        $q = "select * from $table where $pk=$id";
        $last = $this->db()->singleQuery($q,true);
        return array_diff_key($last,['password' => '$%^&*']);
    }

    private function find_columns(): array
    {
        $table = $this->mode ;
        $q = "select * from pragma_table_info('$table')";
        $r = $this->db()->selectCustom($q) ;
        $cols = [];
        foreach($r as $i){
            $cols[] = $i['name'] ;
        }
        return $cols ;
    }

    private function find_pk(): string
    {
        $table = $this->mode ;
        $q = "select name from pragma_table_info('$table') where pk=1";
        return $this->db()->singleQuery($q);
    }

    private function find_last_backup()
    {
        $d = 'data';
        $files = preg_grep("#^backup-\d+#",scandir($d));
        $h = '0';
        $fn = 'backup-0';
        foreach ($files as $f) {
            $t = filemtime("$d/$f");
            if($t > $h){
                $h = $t ;
                $fn = $f ;
            }
        }
        return $fn;
    }

    private function find_services($type = null): array
    {
        $id = $this->data->data->id ?? 0 ;
        $type = $this->data->data->type ?? $type ?? 'device' ;
        if($type != 'all' && !$id){ return []; }
        $field = $type == 'device' ? 'device' : 'planId';
        $where = "where $field=$id and" ;
        if($type == 'all') $where = 'where' ;
        $q = "select id from services $where status not in (0,2,5,8)";
        $r = $this->cachedb()->selectCustom($q) ?? [];
        $ids = [];
        foreach($r as $i){ $ids[] = $i['id']; }
        MyLog()->Append(['find_services','items: '. sizeof($ids)]);
        return $ids ;
    }

    private function fast_finish()
    {
        if($this->fast){ return; }
        respond('ok',false,$this->result ?? []);
        $this->fast = true ;
        if(function_exists('fastcgi_finish_request')){
            fastcgi_finish_request();
        }
    }

    private function set_qos($type = 0): array
    {
        $this->fast_finish();
        MyLog()->Append("set_qos type: ". $type);
        $ids = $this->find_services() ;
        if(!$ids || !$type){ return []; }
        $batch = new Batch();
        if($type > 0){
           $done = $batch->set_accounts($ids);
        }
        else{
            $done = $batch->del_queues($ids);
        }
        if($done){MyLog()->Append(['set_qos_success',"items: ".sizeof($ids)]);}
        return [] ;
    }

    private function cache_build($edit = false): array
    {
        $this->fast_finish();
        if($edit && !$this->name_change()){ return [1]; }
        $api = new ApiCache() ;
        $api->sync(true);
        MyLog()->Append("device_cache_success");
        return [1] ;
    }

    private function qos_change(): int
    {
        if($this->mode != 'devices'){ return 0; }
        $edit = $this->data->data->qos ?? null ;
        if(!is_int($edit)){ return 0 ; }
        $id = $this->data->data->id ?? 0 ;
        $saved = $this->db()->singleQuery("select qos from devices where id=$id");
        if($edit === $saved){ return 0 ;}
        return $edit ? 1 : -1;
    }

    private function name_change(): bool
    {//if device name has changed
        $name = $this->data->data->name ?? null ;
        return $this->mode == 'devices' && $name ;
    }

    private function set_mode($mode)
    {
        if(preg_match("#(service|device|plan)#",$mode)){ //append ending "s"
            $mode = preg_replace("#s\s*$#",'',$mode) . 's';
        }
        $this->mode = $mode ;
    }

    private function validateDevice(array $data): void
    {
        if (!in_array($data['api_transport'] ?? 'plain', ['plain', 'tls'], true)) throw new InvalidArgumentException('Invalid API transport');
        foreach (['port' => [1,65535], 'api_timeout' => [1,60]] as $key => [$min,$max]) {
            if (isset($data[$key]) && filter_var($data[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) throw new InvalidArgumentException('Invalid ' . $key);
        }
        $host = trim($data['ip'] ?? '', '[]');
        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new InvalidArgumentException('Invalid router IP or hostname');
        if (!in_array($data['mode'] ?? 'api', ['api','radius'], true)) throw new InvalidArgumentException('Invalid provisioning mode');
        if (!empty($data['tls_ca_file']) && (!is_file($data['tls_ca_file']) || !is_readable($data['tls_ca_file']))) throw new InvalidArgumentException('CA file must exist on the UISP server');
    }

    private function ucrm(): ApiUcrm { return new ApiUcrm(); }

    private function db(): ApiSqlite { return mySqlite(); }

    private function cachedb(): ApiSqlite { return myCache(); }

    public function status(): object { return new stdClass(); }

    public function result() { return $this->result; }

    public function __construct($data = null,$mode = 'services')
    {
        $this->set_mode($mode);
        if(is_array($data)){ $data = json_decode(json_encode($data));}
        if(!$data){$data = new stdClass(); }
        $this->data = $data ;
    }
}
