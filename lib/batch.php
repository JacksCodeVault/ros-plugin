<?php
include_once 'mt.php';
include_once 'mt_data.php';
include_once 'api_ip.php';
include_once 'api_sqlite.php';
include_once 'api_sites.php';
include_once 'radius_backend.php';
include_once 'job_store.php';

class Batch
{
    private ?array $_plans = null;
    private ?array $_devices = null ;
    private array $_apis = [];
    private array $batch_failed = [];
    private array $batch_success = [];

    public function del_queues($ids): bool
    {
        $deviceServices = $this->find_services($ids, 'delete');
        $plans = $this->find_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did) {
            if ($did == 'nodev') { continue;}
            $api = $this->datapi($did);
            foreach ($deviceServices[$did] as $service) {
                $plan = $plans[$service['planId']] ?? null;
                if (!$plan) {
                    $plan = $this->make_plan($service);
                } //generate plan if not found
                $api->set_data($service, $plan);
                $queue = $api->queue();
                if($queue){
                    $queue['action'] = 'remove';
                    $deviceData[$did]['queues'][] = $queue ;
                }
            }
        }
        $this->run_batch($deviceData,true);
        return empty($this->batch_failed);
    }

    public function del_parents(): bool
    {
        $plans = $this->find_plans();
        $devices = $this->find_devices();
        $deviceData = [];
        foreach(array_keys($devices) as $did){
            foreach ($plans as $plan){
                $parent = [
                    'path' => '/queue/simple',
                    'action' => 'remove',
                    'name' => sprintf('servicePlan-%s-parent',$plan['id']),
                ];
                $deviceData[$did]['parents'][] = $parent ;
            }
        }
        $this->run_batch($deviceData,true);
        return empty($this->batch_failed);
    }

    public function del_accounts(array $ids): bool
    {
        $deviceServices = $this->find_services($ids,'delete');
        $plans = $this->find_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did){
            if($did == 'nodev'){ continue; }
            $api = $this->datapi($did);
            foreach($deviceServices[$did] as $service){
                $plan = $plans[$service['planId']] ?? null;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $data = $api->get_data($service,$plan);
                foreach(array_keys($data) as $key){
                    $item = $data[$key];
                    $item['action'] = 'remove';
                    $deviceData[$did][$key][] = $item;
                }
            }
        }
        return $this->performTracked($deviceServices, $deviceData, true);
    }

    public function set_queues(array $ids,$on = true)
    {
        $deviceServices = $this->find_services($ids,'update');
        $plans = $this->find_plans();
        $deviceData = [];
        $device_ids = [];
        foreach (array_keys($deviceServices) as $did) {
            $api = $this->datapi($did);
            $device_ids[] = $did;
            foreach ($deviceServices[$did] as $service) {
                if ($did == 'nodev') { continue; }
                $plan = $plans[$service['planId']] ?? null ;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $api->set_data($service,$plan);
                $queue = $api->queue();
                if($queue){
                    $queue['action'] = $on ? 'set' : 'remove';
                    $deviceData[$did]['queues'][] = $queue ;
                }
            }
        }
        $this->run_batch($deviceData);
        $conf = $this->conf->disabled_routers ?? null;
        $routers = $conf ? explode(',',$conf) : [];
        if(!$on){
            $routers = array_merge($routers,$device_ids);
        }
        else {
            $routers = array_diff($routers,$device_ids);
        }
        $this->db()->saveConfig(['disabled_routers' => implode(',',$routers)]);
    }

    public function set_accounts(array $ids): bool
    {
        $deviceServices = $this->find_services($ids,'update');
        $plans = $this->find_plans();
        $deviceData = [];
        foreach (array_keys($deviceServices) as $did){
            $api = $this->datapi($did);
            foreach ($deviceServices[$did] as $service){
                if($did == 'nodev'){ continue; }
                $plan = $plans[$service['planId']] ?? null ;
                if(!$plan){ $plan = $this->make_plan($service); } //generate plan if not found
                $data = $api->get_data($service,$plan);
                foreach(array_keys($data) as $key){
                    $deviceData[$did][$key][] = $data[$key];
                }
            }
        }
        return $this->performTracked($deviceServices, $deviceData, false);
    }

    private function set_sites($ids,$delete = false)
    {
        $token = $this->find_unms_key();
        if(!(is_string($token) && strlen(trim($token)) >= 36)){ return; }
        $nms = new ApiSites();
        if($delete){ $nms->delete($ids);}
        else{ $nms->set($ids); }
    }

    private function find_unms_key()
    {
        if(!is_file('data/config.json')){ return null; }
        $read = json_decode(file_get_contents('data/config.json'));
        if(!is_object($read)){ return null; }
        return $read->unmsToken ?? null ;
    }

    private function run_batch($deviceData,$delete = false)
    {
        $timer = new ApiTimer("device batch write");
        $sent = 0 ;
        $writes = 0 ;
        $this->batch_failed = [];
        $this->batch_success = [];
        foreach (array_keys($deviceData) as $did)
        {
            $device  = $this->find_device($did);
            if(!is_object($device)){ throw new RuntimeException('Missing target device'); }
            $type = $device->type ?? 'mikrotik';
            $mode = $device->mode ?? 'api';
            $api_type = ($mode === 'radius') ? 'radius' : $type;
            $api = $this->device_api($api_type);
            $keys = ['pool','parents','profiles','queues','accounts','dhcp6','disconn'];
            if($delete) {
                $keys = array_reverse(array_diff($keys,['disconn']));
                $keys[] = 'disconn'; //disconnect at the end
            }
            foreach ($keys as $key){
                $item = $deviceData[$did][$key] ?? [];
                $batch = array_values($item);
                if (!$batch) continue;
                // A dependency failure prevents downstream account changes for that service.
                $batch = array_values(array_filter($batch, function ($command) {
                    return !isset($this->batch_failed[$command['batch'] ?? '']);
                }));
                if (!$batch) continue;
                $sent += sizeof($batch);
                $writes += $api->do_batch($device,$batch);
                $this->batch_success = array_replace($this->batch_success,$api->success());
                $this->batch_failed = array_replace($this->batch_failed,$api->failed());
            }

        }
        $timer->stop();
        MyLog()->Append(sprintf('batch sent: %s written: %s',$sent,$writes));
    }

    private function save_batch($deviceServices)
    {
        if(empty($this->batch_success)){ return ;}
        $fill = array_fill_keys(['id','device','clientId',
            'planId','status'],'%%$#');
        $save = [];
        $sites = [];
        $now = date('c');
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $success = $this->batch_success[$id] ?? null ;
                if($success && !isset($this->batch_failed[$id])){
                    $sites[] = $service['id'] ;
                    $values = array_intersect_key($service,$fill);
                    $values['last'] = $now ;
                    $save[] = $values;
                }
            }
        }
        $this->ip_flush();
        $this->db()->insert($save,'services',INSERT_REPLACE);
        $this->set_sites($sites);
    }

    private function unsave_batch($deviceServices): void
    {
        if(empty($this->batch_success)) return ;
        $ids = [];
        foreach ($deviceServices as $services){
            foreach ($services as $service){
                $id = $service['batch'] ?? null ;
                $success = $this->batch_success[$id] ?? null ;
                if($success && !isset($this->batch_failed[$id])){
                    $ids[] = $service['id'];
                }
            }
        }
        $this->ip_flush();
        foreach(['services','network'] as $table) {
            $sql = sprintf("delete from %s where id in (%s)",$table,
                implode(',',$ids));
            $this->db()->exec($sql);
        }
        $this->set_sites($ids,true);
    }

    public function replay(array $payload): bool
    {
        $device = (object)$payload['device'];
        $this->_devices = [$device->id => (array)$device];
        $this->run_batch([$device->id => $payload['commands']], (bool)$payload['delete']);
        if ($this->batch_failed) return false;
        // Old-resource deletes must not erase the replacement service's local state.
        if (!$payload['delete']) $this->save_batch([[$payload['service']]]);
        return true;
    }

    private function performTracked(array $deviceServices, array $deviceData, bool $delete): bool
    {
        $store = new JobStore();
        $jobs = [];
        // Commit every command snapshot before the first router/database side effect.
        foreach ($deviceServices as $services) foreach ($services as $service) {
            $commands = [];
            foreach ($deviceData[$service['device']] ?? [] as $key => $items) {
                foreach ($items as $item) if (($item['batch'] ?? null) === $service['batch']) $commands[$key][] = $item;
            }
            $payload = ['service' => $service, 'device' => (array)$this->find_device($service['device']), 'commands' => $commands, 'delete' => $delete];
            $jobs[] = [$store->enqueueLatest($payload), $payload];
        }
        $ok = true;
        foreach ($jobs as [$id, $payload]) {
            if (!$delete && $store->hasDelete((int)$payload['service']['id'])) { $ok = false; continue; }
            $store->start($id);
            try {
                if (!$payload['commands']) throw new RuntimeException('No provisioning commands generated');
                $done = $this->replay($payload);
                if ($done && $delete) $this->unsave_batch([[$payload['service']]]);
                $store->finish($id, $done, 1, $done ? '' : 'Required provisioning operation failed');
                $ok = $done && $ok;
            } catch (Throwable $e) {
                $store->finish($id, false, 1, 'Provisioning interrupted; automatic retry scheduled');
                MyLog()->Append('Tracked provisioning failed: ' . get_class($e));
                $ok = false;
            }
        }
        return $ok;
    }

    private function device_api($type): ?object
    {
        if ($type === 'radius') {
            if (!isset($this->_apis['radius'])) {
                $this->_apis['radius'] = new RadiusBackend();
            }
            return $this->_apis['radius'];
        }
        if(!isset($this->_apis[$type])){
            if($type != 'mikrotik'){ fail('device_invalid'); }
            $this->_apis['mikrotik'] = new MT();
        }
        return $this->_apis[$type] ?? null ;
    }

    private function datapi($did): MtData
    {
        $device = $this->find_device($did);
        if(!is_object($device)){ fail('device_invalid'); }
        $type = $device->type ?? null ;
        if($type != 'mikrotik'){ fail('device_invalid'); }
        return new MtData();
    }

    private function find_plans(): array
    {
        if(empty($this->_plans)){
            $api = new ApiList([],'plans');
            $api->list();
            $st =[
                'SELECT id,name,ratio,uploadSpeed,downloadSpeed,ifnull(priorityUpload,8) as priorityUpload,',
                'ifnull(priorityDownload,8) as priorityDownload,ifnull(timeUpload,1) as timeUpload,',
                'ifnull(timeDownload,1) as timeDownload,last,created,',
                'CASE WHEN limitUpload = 0 THEN 0 ELSE (uploadSpeed * 1000000) - ((limitUpload * uploadSpeed * 1000000) / 100) END as limitUpload,',
                'CASE WHEN limitDownload = 0 THEN 0 ELSE (downloadSpeed * 1000000) - ((limitDownload * downloadSpeed * 1000000) / 100) end as limitDownload, ',
                'CASE WHEN threshUpload = 0 THEN 0 ELSE (uploadSpeed * 1000000) - ((threshUpload * uploadSpeed * 1000000) / 100) END as threshUpload,',
                'CASE WHEN threshDownload = 0 THEN 0 ELSE (downloadSpeed * 1000000) - ((threshDownload * downloadSpeed * 1000000) / 100) END as threshDownload,',
                'CASE WHEN burstUpload = 0 THEN 0 ELSE (uploadSpeed * 1000000) + ((burstUpload * uploadSpeed * 1000000) / 100) END as burstUpload,',
                'CASE WHEN burstDownload = 0 THEN 0 ELSE (downloadSpeed * 1000000) + ((burstDownload * downloadSpeed* 1000000) / 100) END as burstDownload',
                'FROM plans',

            ];
            $plans = $this->db()->selectCustom(implode(' ',$st));
            foreach ($plans as $plan){
                $this->_plans[$plan['id']] = $plan; }
        }
        return $this->_plans ;
    }

    private function find_services(array $ids, $action): array
    {
        $data = $ids ;
        if (!$ids) return [];
        $first = array_values($ids)[0] ;
        if(!is_array($first)){
            $fields = 'services.*,clients.company,clients.firstName,clients.lastName,'.
                'network.address,network.address6';
            foreach ($ids as $id) if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) throw new InvalidArgumentException('Invalid service ID');
            $sql = sprintf("SELECT %s FROM services LEFT JOIN clients ON services.clientId=clients.id ".
                "LEFT JOIN network ON services.id=network.id ".
                "WHERE services.id IN (%s) ",$fields,implode(',',$ids));
            $data = $this->dbCache()->selectCustom($sql) ?? [];
        }
        $deviceMap = [];
        $count = 0;
        $date = date('c');
        foreach ($data as $item){
            $item['action'] ??= $action ;
            $item['batch'] = $date . '-' . ++$count;
            $id = $item['device'] ?? 'nodev';
            $deviceMap[$id][] = $item ;
        }
        return $deviceMap ;
    }

    private function find_device($did): ?object
    {
        $dev = $this->find_devices()[$did] ?? null ;
        return $dev ? (object) $dev : null ;
    }

    private function find_devices(): array
    {
        if(empty($this->_devices)){
            $this->_devices = [];
            $devs = $this->db()->selectAllFromTable('devices') ?? [];
            foreach ($devs as $dev){ $this->_devices[$dev['id']] = $dev; }
        }
        return $this->_devices ;
    }

    private function ip_flush(): void
    {
        myIPClass()->flush();
    }

    private function make_plan($service): array
    {
        $keys = ['limitUpload','limitDownload', 'burstUpload','burstDownload',
            'threshUpload','threshDownload'];
        $ul = $service['uploadSpeed'] ?? 1 ;
        $dl = $service['downloadSpeed'] ?? 1 ;
        $defaults = ['ratio' => 1, 'priorityUpload' => 8,'priorityDownload' => 8,
            'timeUpload' => 1,'timeDownload' => 1, 'uploadSpeed' => $ul,'downloadSpeed' => $dl];
        $fill = array_fill_keys($keys,0);
        $plan = array_replace($fill,$defaults);
        $plan['name'] = sprintf('Custom Plan %s/%s',$ul,$dl);
        return $plan ;
    }

    private function db(): ApiSqlite
    {
        return mySqlite();
    }

    private function dbCache(): ApiSqlite
    {
        return myCache();
    }

}
