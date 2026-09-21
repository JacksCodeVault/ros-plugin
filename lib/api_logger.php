<?php

const API_DEBUG_ECHO = 0;

class ApiLogger
{
    public function Append($entry, $level = LOG_DEBUG)
    {
        if (!$this->Debug() && $level >= LOG_DEBUG){ return; }
        $fn = 'data/plugin.log';
        $entry = self::redact($entry);
        if(is_array($entry) || is_object($entry)){ $entry = json_encode($entry); }
        error_log(sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL,3,$fn);
        if(API_DEBUG_ECHO) echo sprintf("%s: %s",$this->Time(), $entry) . PHP_EOL;
    }

    public static function redact($value)
    {
        if (is_object($value)) $value = (array)$value;
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = preg_match('/password|passwd|secret|token|appkey|authorization|radius_pass|pppoe_pass/i', (string)$key) ? '[REDACTED]' : self::redact($item);
            }
            return $value;
        }
        if (is_string($value)) {
            $value = preg_replace('/("(?:[^"\\\\]|\\\\.)*(?:password|secret|token|radius_pass|appkey)[^"\\\\]*"\\s*:\\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"[REDACTED]"', $value);
            $value = preg_replace('/((?:password|secret|token|radius_pass|appkey)\\s*[=:]\\s*)[^\\s&,]+/i', '$1[REDACTED]', $value);
        }
        return $value;
    }

    private function Debug(): bool
    {
//        return '1'; //testing
        $fn = 'data/config.json';
        if(!is_file($fn)){ return false ;}
        $conf = json_decode(file_get_contents($fn));
        if(is_object($conf)){
            return $conf->debugEnable ?? false ;
        }
        return false;
    }

    private function Time(): string
    {
        return date('Y-m-d H:i:s.v');
    }
}

$apiLogger = null ;

function MyLog(): ApiLogger
{
    global $apiLogger;
    if(empty($apiLogger)){
        $apiLogger = new ApiLogger();
    }
    return $apiLogger ;
}
