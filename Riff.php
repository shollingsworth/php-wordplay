<?php
/**
 * Riff: Redis if f****** fast interface
 * @version 2014-10-20 13:16
 * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
 */

/*
Requires Redis Server and php5-redis package
   Package: php5-redis
   Status: install ok installed
   Priority: optional
   Section: php
   Installed-Size: 351
   Maintainer: Ubuntu Developers <ubuntu-devel-discuss@lists.ubuntu.com>
   Architecture: amd64
   Source: php-redis
   Version: 2.2.4-1build2
   Depends: libc6 (>= 2.14), phpapi-20121212
   Suggests: redis-server
   Description: PHP extension for interfacing with Redis
   This extension allows php applications to communicate with the Redis
   persistent key-value store. The php-redis module provides an easy object
   oriented interface.
   Original-Maintainer: Debian PHP PECL Maintainers <pkg-php-pecl@lists.alioth.debian.org>
   Homepage: http://pecl.php.net/package/redis
*/

/**
 * Riff: Redis if f****** fast interface
 * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
 * @version 2014-10-20 13:16
 */
abstract class Riff {
   /**
    * @const KEYSEP KEY Separator String
    */
   const KEYSEP = "\x02:"; //chr(2) . ":"

   /**
    * @const KEYFLAT Key Separator when flattened
    */
   const KEYFLAT = ":"; //used when we flatten a keyid

   /**
    * @const COLTRACKERKEY Column Key Tracker Name
    */
   const COLTRACKERKEY = 'COLTRACKER';

   /** redis
    * @var Redis Redis Class Object
    */
   protected $redis;

   /** Instance PREFIX */
   protected $prefix = null;

   /**
    * Consructor
    */
   abstract protected function __construct();

   /**
    * Generate Redis Key based on prefix, middle (key) , and end $keyid
    * @version 2014-10-20 18:06
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @param string $subkey
    * @param string $keyid
    */
   public function genKey($subkey=NULL,$keyid=NULL) {
      if(is_array($subkey)) {
         $subkey = implode($this::KEYSEP,$subkey);
      } else if(is_null($subkey)) {
         return $this->prefix . $this::KEYSEP;
      }

      if(is_null($keyid)) {
         return implode($this::KEYSEP,array($this->prefix,$subkey));
      } else {
         return implode($this::KEYSEP,array($this->prefix,$subkey,$keyid));
      }
   }

   /**
    * Get Last part of Key which should be {x} unique value for subkey
    * @version 2014-10-20 18:50
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @param string $key description 
    */
   public function getKeyID($key) {
      $arr = explode($this::KEYSEP,$key,3);
      $tmp = array_pop($arr);
      return implode($this::KEYFLAT,explode(self::KEYSEP,$tmp));
   }

   /**
    * Search Redis Keyspace
    * @version 2014-10-20 18:42
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @param string $subkey
    */
   public function keySpace($subkey=NULL) {
      if(is_null($subkey)) {
         $key = $this->genKey();
      } else {
         $key = $this->genKey($subkey);
      }
      return $this->redis->keys($key . '*');
   }


   /**
    * @const ARRAYFMT Array Format
    */
   const ARRAYFMT = 'ARRAY';

   /**
    * Get Info
    * @version 2014-10-20 13:28
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    */
   public function info($format=NULL) {
      $likey = array(
         'redis_version'
         ,'uptime_in_seconds'
         ,'config_file'
         ,'used_memory_human'
         ,'used_memory_peak_human'
         ,'mem_fragmentation_ratio'
         ,'rdb_last_save_time'
         ,'rdb_last_bgsave_status'
         ,'rdb_last_bgsave_time_sec'
         ,'total_connections_received'
         ,'total_commands_processed'
         ,'instantaneous_ops_per_sec'
         ,'keyspace_hits'
         ,'keyspace_misses'
         ,'repl_backlog_size'
         ,'used_cpu_sys'
         ,'used_cpu_user'
      );

      $arr = $this->redis->info();
      $max_vlen = 0;
      $max_klen = 0;
      $ret_arr = array();
      foreach ($arr as $key => $value){
         if(!in_array($key,$likey)) continue;
         switch ($key) {
            case 'uptime_in_seconds':
               $value = "$value seconds";
               break;
            case 'rdb_last_save_time':
               $value = date("m/d/Y H:i:s",$value);
               break;
         }

         $v_len = strlen($value);
         $k_len = strlen($key);
         if($v_len > $max_vlen) $max_vlen = $v_len;
         if($k_len > $max_klen) $max_klen = $k_len;

         $tmparr = array(); //INIT array tmparr
         foreach (explode('_',$key) as $kvalue) $tmparr[] = ucfirst($kvalue);
         $key = implode(' ',$tmparr);

         $ret_arr[$key] = $value;
      }

      switch ($format) {
         case self::ARRAYFMT:
            return $ret_arr;
            break;
      }
      $out = '';
      foreach ($ret_arr as $key => $value){
         $out .= str_pad($key,$max_klen + 2,' ') . str_pad($value,$max_vlen,' ') . "\n";
      }
      return $out;
   }

   /**
    * @const SPLITPARTS Split Parts on addSetIndex
    */
   const SPLITPARTS = TRUE;

   /**
    * Generate 
    * @version 2014-10-20 21:22
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @param string $key
    * @param string $gen_col
    * @param bool $split
    * @param string $split_delim
    * @example
    * <code>
    * WaterReport::genRedisKeyColTagID('TAG','Description'); <br>
    * print_r( self::Redis()->sGetMembers(RedCache::genKey('Foreground High Pressure'))); <br>
    * </code>
    * @return int
    */
   public function addSetIndex($main_key,$gen_col,$split=FALSE,$split_delim=' ') {


      $col_tracker_key = $this->genKey($this::COLTRACKERKEY);
      $this->redis->sAdd($col_tracker_key,$gen_col);
      $cnt = 0;
      if(empty($this->keySpace($main_key))) {
         throw new Exception("Error $main_key Keyspace is empty. You might want to run genDataSet on the array your indexing first");
      }
      foreach ($this::keySpace($main_key) as $row_key){
         $val = $this->redis->hGet($row_key,$gen_col);
         if(!$split) {
            $cnt++;
            $new_key = $this->genKey(array($gen_col,$val));
            $this->redis->sAdd($new_key,$row_key);
            continue;
         }

         //We're splitting the value
         foreach (explode($split_delim,$val) as $split_value) {
            $cnt++;
            $new_key = $this->genKey(array($gen_col,$split_value));
            $this->redis->sAdd($new_key,$row_key);
         }
      }
      return $cnt;
   }

   /**
    * Remove Set Index
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-23 09:12
    * @param string $subkey Subkey to remove
    */
   public function removeSetIndex($subkey) {
      $keys = $this->keySpace($subkey);
      $this->redis->del($keys);
   }

   /**
    * Generate DataSet with Data Array (given constant values from say a sql query)
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-20 18:35
    */
   public function genDataSet($main_key_name,$identifier,$data) {
      foreach ($data as $row) {
         $id = $row[$identifier];
         $key = $this->genKey($main_key_name,$id);
         $this->redis->hMset($key,$row);
      }
   }


   /**
    * Search Keys based on Array of Key/Value Pairs - See $this->getSearchbyPairs
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-23 09:34
    * @param array $arrayHash
    * @example
    * <code>
    * $search = array(); <br>
    * $search[] = array('PLC_ADDRESS' => '40001'); <br>
    * $search[] = array('Parts' => '!Pump'); <br>
    * $search[] = array('Parts' => '!Booster'); <br>
    * $keys =  $this->getSearchbyArray($search); <br>
    * </code>
    */
   public function getSearchbyArray($AoA) {
      $arr = array();
      foreach ($AoA as $arrayHash){
         foreach ($arrayHash as $key => $value){
            $arr[] = "$key" . $this::KEYFLAT . "$value";
         }
      }
      return $this->getSearchbyPairs($arr);
   }

   /**
    * Search A Subkey / SubKey Value Pair to Get Elements create by RedCache::addSetIndex(...) given multiple subkey:subkey_value pairs in array format
    * @version 2014-10-21 17:06
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @param array $pairArray array('type:blarg','description:boop')
    * @example
    * <code>
    * $pairs = array(); <br>
    * $pairs[] = 'idtype:!blarg'; (! == NOT idtype blarg) <br>
    * $pairs[] = 'tid:1'; (tid = 1)<br> 
    * $pairs[] = 'DataType:2'; (DataType = 2) <br>
    * print_r($this->getSearchbyPairs($pairs)); <br>
    * </code>
    * @return array array of Set keys
    */
   public function getSearchbyPairs($pairArray) {
      $keys = array();
      $diffkeys = array();
      $ret_arr = array();
      if(is_string($pairArray)) $pairArray = (array)$pairArray; //convert to array if string

      foreach ($pairArray as $pair){
         list($subkey,$key_val) = explode($this::KEYFLAT,$pair,2); //only explode to the first separator
         if(is_null($subkey) or (is_null($key_val))) { throw new Exception("Pair: $pair should have key:value relationship mmmmkay"); }

         if(empty($subkey) or ((empty($key_val) and (!$key_val == 0)))) {
            throw new Exception("Pair: $pair cannot be empty mmmmkay"); 
         }

         if(preg_match("/^!/",$key_val)) {
            $diffkey = TRUE;
            $key_val = preg_replace("/^!/",'',$key_val);
         } else {
            $diffkey = FALSE;
         }

         $key = $this->genKey($subkey,$key_val);
         if(!$this->redis->exists($key)) {
            Tracker::getInstance()->addError("$key does not exist in Redis DB");
            return array();
         }

         if($diffkey) {
            $diffkeys[] = $key;
         } else {
            $keys[] = $key;
         }
      }

      if(!empty($keys)) {
         $include_key = $this->genKey('TMP',uniqid('INCLUDE_SEARCH_'));
         array_unshift($keys,$include_key);
         $this->redis->sInterStore($keys);
         $this->redis->expire($include_key,60); //only need this for a few seconds, then punt
      } else { //if keys are empty that means we're only doing exclusion
         throw new Exception("We need at least one positive search subkey!");
      }

      if(!empty($diffkeys)) {
         $diff = array_merge((array)$include_key,$diffkeys);
         $keys = $this->redis->sDiff($diff);
      }  else {
         $keys = $this->getKeyVal($include_key);
      }
      return $keys;
   }

   /**
    * Get List and Expire Times of TMP Keys
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-23 14:30
    */
   public function getTMPKeys() {
      $keys = $this->keySpace('TMP');
      $arr = array();
      foreach ($keys as $key){
         $exp = $this->redis->ttl($key);
         $msg = "$key => expires: $exp";
         $arr[] = array(
            'key' => $key
            ,'expires' => $exp
         );
      }
      return $arr;
   }

   /**
    * Get Indexed Subkey and Associated Values
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-24 13:15
    * @param type  description
    * @return type desc
    */
   public function getSubKeyValues($select_key=NULL) {
      $subkeys = $this->getSubKeys();
      $retarr = array();
      foreach ($subkeys as $subkey){
         //continue if select key exists and subkey is not != selectkey
         if(!is_null($select_key) and ($subkey != $select_key)) {
            continue; 
         }

         $key_arr = $this->keySpace($subkey);
         foreach ($key_arr as $key) {
            $subkey_value = $this->getKeyID($key);
            $retarr[$subkey][$subkey_value] = $key;
         }
      }
      if(!empty($retarr) and (!is_null($select_key))) {
         return $retarr[$select_key];
      } else {
         return $retarr;
      }
   
   }

   /**
    * Get a List of a Indexed Subkeys and corresponding values
    * @version 2014-10-21 17:25
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @return array
    */
   public function getSubKeys() {
      $subkeys = $this->redis->sGetMembers($this->genKey(self::COLTRACKERKEY));
      return $subkeys;
   }

   /**
    * Get Values for an Array of Keys
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-23 09:15
    * @param array  $keys 
    * @return array  
    */
   public function getKeyValues($keys) {
      if(empty($keys)) {
         return NULL;
      }
      foreach ($keys as $key){
         if(empty($arr)) $arr = array();
         $arr[] = $this->getKeyVal($key);
      }
      return $arr;
   }

   /**
    * Get Key Value Regardless of Type
    * @version 2014-10-21 17:33
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @param string $key
    */
   public function getKeyVal($key) {
      $type = $this->redis->type($key);
      switch ($type) {
         case Redis::REDIS_HASH:
            return $this->redis->hGetAll($key);
            break;
         case Redis::REDIS_LIST:
            return $this->redis->lGetRange($key,0,-1);
            break;
         case Redis::REDIS_SET:
            return $this->redis->sGetMembers($key);
            break;
         case Redis::REDIS_STRING:
            return $this->redis->get($key);
            break;
         case Redis::REDIS_ZSET:
            return $this->redis->zRangeByScore($key,0,-1);
            break;
         default:
            return FALSE;
            break;
      }
   }
} 
// Note that it is a good practice to NOT end your PHP files with a closing PHP tag. 
// This prevents trailing newlines on the file from being included in your output, 
// which can cause problems with redirecting users.
