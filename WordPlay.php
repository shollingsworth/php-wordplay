<?php
/**
 * WordPlay : Dictionary / Word Finder thinger Demo 
 * @version 2014-10-24 14:18 
 * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
 */


require_once("./Riff.php");

/**
 * WordPlay : Dictionary / Word Finder thinger Demo 
 * @version 2014-10-24 14:18 
 * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
 * @package data
 */
class WordPlay extends Riff {
   /** Instance variable defaults to null, when null a new one will be created */
   protected static $instance = null;

   /**
    * Get Instance of self, if exists, otherwise create one
    * @version 2014-07-27 17:37
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @return WordPlay
    */
   public static function getInstance() {
      if (is_null(self::$instance)) { self::$instance = new self; }
      return self::$instance;
   }

   /**
    * Get Redis Instance
    * @version 2014-10-23 08:48
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @return Redis
    */
   public static function Redis() {
      return self::getInstance()->redis;
   }

   /**
    * @const TAGNAME Redis Tag Subkey
    */
   const MAINKEY = 'WORDS';

   /**
    * Consructor
    */
   protected function __construct() {
      $this->redis = new Redis();
      $this->redis->connect('localhost',6379,'60');
      $this->prefix = __CLASS__;
      $this->wordlist_file = "/usr/share/dict/american-english";
      if(empty($this->keySpace())) {
         $this->compileDB();
      }
   }

   /**
    * Get Words Array
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-26 09:32
    */
   public function getWordsArray() {
      $key = $this->genKey($this::MAINKEY);
      return $this->redis->lRange($key,0,-1);
   }

   /**
    * Prepare Database
    * @version 2014-10-26 09:22
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @param string $arg description 
    */
   public function compileDB() {
      $this->redis->del($this->keySpace()); //nuke
      $words = explode("\n",file_get_contents($this->wordlist_file));
      $key = $this->genKey($this::MAINKEY);
      foreach ($words as $word){
         if(empty(trim($word))) continue;
         $this->redis->lPush($key,$word);
      }

      $this->compileIndexes();
      //$this->compileAnagrams();
   }

   /**
    * Generate a hash of attributes of the word to index
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-26 09:37
    * @param string $word 
    * @return array  
    */
   public function getWordAttributes($word) {
      $oparts_arr = array();
      $wa = str_split(strtolower($word));

      //letter positions
      if(empty($posarr)) $posarr = array(); //INIT array posarr
      foreach ($wa as $pos => $letter) $posarr[] = $pos + 1 . "{$letter}"; 

      $parts_arr = array_unique($wa);
      $wa_arr = array_count_values($wa);
      $max_rep = 0;
      foreach ($wa_arr as $wakey => $waval){
         if($waval > $max_rep) { $max_rep = $waval; }
         $oparts_arr[] = "{$wakey}{$waval}";
      }
      asort($oparts_arr);
      asort($parts_arr);

      $unique_char_cnt = count($oparts_arr);
      $oparts = implode(' ',$oparts_arr);
      $parts = implode(' ',$parts_arr);

      return array(
         'oparts' => $oparts
         ,'positions' => implode(' ',$posarr)
         ,'unique_cnt' => $unique_char_cnt
         ,'length' => strlen($word)
         ,'max_repeated' => $max_rep
      );
   }

   /**
    * Compile Indexes
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-26 10:22
    */
   protected function compileIndexes() {
      $words = $this->getWordsArray();  
      if(empty($coltrackervals)) $coltrackervals = array();
      $coltrackervals['opart_element'] = NULL;
      $coltrackervals['part_element'] = NULL;
      $coltrackervals['position_element'] = NULL;
      foreach ($words as $idx => $word){
         $dat = $this->getWordAttributes($word); 
         foreach ($dat as $idxname => $idxnameval){
            //break out oparts to the individual elements
            switch ($idxname) {
               case 'oparts':
                  $this->redis->sAdd($this->genKey('opart_keys'),$idxnameval);
                  $arr = explode(' ',$idxnameval);
                  foreach ($arr as $element){
                     $this->redis->sAdd($this->genKey('opart_element',$element),$idx);
                     $this->redis->sAdd($this->genKey('part_element', substr($element,0,1)),$idx);
                  }
                  break;
               case 'positions':
                  $arr = explode(' ',$idxnameval);
                  foreach ($arr as $element){
                     $this->redis->sAdd($this->genKey('position_element',$element),$idx);
                  }
                  continue;
                  break;
            }
            //generate off of base elements as default unless continue called above
            $coltrackervals[$idxname] = NULL;
            $key = $this->genKey($idxname,$idxnameval);
            $this->redis->sAdd($key,$idx);
         }
      }

      $col_tracker_key = $this->genKey($this::COLTRACKERKEY);
      foreach ($coltrackervals as $name => $null){
         $this->redis->sAdd($col_tracker_key,$name);
      }
   }

   /**
    * Get List of words from index values
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-26 15:59
    * @param array  $indexes 
    */
   public function getWordsFromIndexes($indexes) {
      if(empty($elarr)) $elarr = array();
      if(empty($indexes)) return array();
      foreach ($indexes as $idx){
         $key = $this->genKey($this::MAINKEY);
         $elarr[] = $this->redis->lGet($key,$idx);
      }
      return $elarr;
   }

   /**
    * Compile a list of anagrams
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-26 13:39
    */
   protected function compileAnagrams() {
      $col_tracker_key = $this->genKey($this::COLTRACKERKEY);
      $word_key = $this->genKey($this::MAINKEY);
      $this->redis->sAdd($col_tracker_key,'ANAGRAM');
      $this->redis->sAdd($col_tracker_key,'ANAGRAM_COUNT');
      $this->redis->sAdd($col_tracker_key,'ANAGRAM_LENGTH');

      $uniques = $this->getSubKeyValues('oparts');
      $main_key = $this->genKey($this::MAINKEY);
      if(empty($vals)) $vals = array();
      foreach ($uniques as $opart => $key){
         $cnt = $this->redis->sCard($key);
         if($cnt <= 1) continue;
         $keys = $this->getKeyVal($key);
         $match_num = count($keys);
         foreach ($keys as $idx){
            $word = $this->redis->lGet($word_key,$idx);
            $len = strlen($word);

            $opart_key = $this->genKey('ANAGRAM',$opart);
            $this->redis->sAdd($opart_key,$idx);

            $_key = $this->genKey('ANAGRAM_COUNT',$match_num);
            $this->redis->sAdd($_key,$opart_key);

            $_key = $this->genKey('ANAGRAM_LENGTH',$len);
            $this->redis->sAdd($_key,$opart_key);
         }
      }
   }

   /**
    * Get List of words with the same characters and counts
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-26 16:03
    * @param string $word
    * @param bool $same_length search for an anagram
    * @return array  word list
    */
   public function getWordSearch($word,$same_length=FALSE) {
      $br = $this->getWordAttributes($word);
      foreach (explode(' ',$br['oparts']) as $part){
         if(empty($arr)) $arr = array();
         $arr[] = array('opart_element' => $part);
      }
      if($same_length) {
         $arr[] = array('length' => $br['length']);
      }
      $elements = $this->getSearchbyArray($arr);
      return $this->getWordsFromIndexes($elements); 
   }

   /**
    * Get Scrable Score Matrix
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-27 12:09
    */
   public function getScrableMatrix() {
      $tmp_matrix = array(
         'EAIONRTLSU' => 1
         ,'DG' => 2
         ,'BCMP' => 3
         ,'FHVWY' => 4
         ,'K' => 5
         ,'JX' => 8
         ,'QZ' => 10
      );

      foreach ($tmp_matrix as $letters => $score){
         if(empty($retarr)) $retarr = array();
         $l = strtolower($letters);
         foreach (str_split($l) as $letter){
            $retarr[$letter] = $score;
         }
      }
      ksort($retarr);
      return $retarr;
   }

   /** scrabble_matrix
    * @var array  Scrabble Score Matrix with letters as key
    */
   protected $scrabble_matrix = array();

   /**
    * Get Score for Word Combination
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-27 12:11
    * @param string $word 
    * @return int
    */
   public function getScrabbleWordScore($word) {
      if(empty($this->scrabble_matrix)) $this->scrabble_matrix = $this->getScrableMatrix();
      $score = 0;
      $word_arr = str_split($word);
      foreach ($word_arr as $letter){
         if(array_key_exists($letter,$this->scrabble_matrix)) $score += $this->scrabble_matrix[$letter];
      }
      return $score;
   }

   /**
    * Get Wildcard Alpha A-Z values
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-27 12:16
    * @param type $base,$num=1 description
    */
   public function getWildCardValues($base,$num=1) {
      if($num > 3) throw new Exception("More Three two and it's way too much");
      if(empty($retarr)) $retarr = array();
      if($num != 1) { $base_num = $num + strlen($base); } else { $base_num = NULL; }
      $num--;
      $alpha_arr = array(); foreach (range(97,122) as $alpha) $alpha_arr[] = chr($alpha);
      foreach ($alpha_arr as $chr){
         $arr[] = $base . $chr;
      }
      //if I'm zero, just return
      if($num == 0) {
         return $arr;
      }
      foreach ($arr as $newbase){
         $arr = array_merge($arr,$this->getWildCardValues($newbase,$num));
      }
      foreach ($arr as $akey => $word){
         if(strlen($word) != $base_num) {
            unset($arr[$akey]);
         }
      }
      return array_values($arr);
   }

   /**
    * Get Combinations for Letters in String
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-27 12:20
    * @param string $word 
    */
   public function getCombos($word) {
      $letters = str_split($word);
      $num = count($letters); 
      //The total number of possible combinations 
      $total = pow(2, $num);
      //Loop through each possible combination  
      if(empty($retarr)) $retarr = array();
      for ($i = 0; $i < $total; $i++) {  
          //For each combination check if each bit is set 
          $this_arr = array(); 
          for ($j = 0; $j < $num; $j++) { 
             //Is bit $j set in $i? 
              if (pow(2, $j) & $i) $this_arr[] = $letters[$j];
          } 
          if(empty($this_arr)) continue;
          asort($this_arr);
          $retarr[] = implode($this_arr);
      }
      return $retarr;
   }

   /**
    * Get Scrabble Fuzzy Search
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-27 12:21
    * @param string $word
    * @param int $top_results
    */
   public function getFuzzySearch($word,$top_results=10) {
      $opart_keys = $this->getKeyVal($this->genKey('opart_keys'));
      $wild_count = substr_count($word,'*');
      $word = preg_replace("/\*/",'',$word);
      if(empty($words)) $words = array(); //INIT array words
      if($wild_count > 0) {
         $combos = array();
         $t = $this->getWildCardValues($word,$wild_count);
         while($w = array_shift($t)) {
            $score = $this->getScrabbleWordScore($w);
            $combos[$score][] = $w;
         }
         krsort($combos);
      } else {
         $score = $this->getScrabbleWordScore($word);
         $combos = array($score => array($word));
         krsort($combos);
      }
      /* Flatten array, but it'll keep it's score sort */
      $tmp = $combos; $combos = array();
      foreach ($tmp as $t){
         while($tt = array_shift($t)) $combos[] = $tt;
      }
      if(empty($words)) $words = array(); //INIT array words
      foreach ($combos as $word){
         if(empty($tracker)) $tracker = array(); //INIT array tracker
         $tcombo = $this->getCombos($word);
         while($tword = array_shift($tcombo)) {
            if(in_array($tword,$tracker)) continue;
            if(strlen($tword) <= 1) continue;
            $tracker[] = $tword;
            $oparts = $this->getWordAttributes($tword)['oparts'];
            if(in_array($oparts,$opart_keys)) {
               $score = $this->getScrabbleWordScore($tword);
               $words[$score][] = $oparts;
            }
         }
         krsort($words);
      }

      $result_num = 0;
      if(empty($retarr)) $retarr = array(); //INIT array retarr
      foreach ($words as $score => $oparts){
         while($opart = array_shift($oparts)) {
            if($result_num >= $top_results) break;
            $result_num++;
            $key = $this->genKey('oparts',$opart);
            $idxs = $this->getKeyVal($key);
            $tmpwords = $this->getWordsFromIndexes($idxs);
            while($word = array_shift($tmpwords)) {
               $retarr[$score][] = $word;
            }
         }
      }
      krsort($retarr);
      return $retarr;
   }

   /**
    * Get opart Matches
    * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
    * @version 2014-10-27 16:07
    * @param type $word description
    * @return type desc
    */
   public function getOpartMatches($word) {
      $arr = $this->getCombos($word);
      if(empty($retarr)) $retarr = array(); //INIT array retarr
      foreach ($arr as $word){
         if(strlen($word) <= 1) continue;
         $opart = $this->getWordAttributes($word)['oparts'];
         $key = $this->genKey('oparts',$opart);
         if($this->redis->exists($key)) {
            $retarr = array_merge($retarr,$this->getKeyVal($key));
         }
      }
      $retarr = array_unique($retarr);
      return $retarr;
   }
} 
// Note that it is a good practice to NOT end your PHP files with a closing PHP tag. 
// This prevents trailing newlines on the file from being included in your output, 
// which can cause problems with redirecting users.
