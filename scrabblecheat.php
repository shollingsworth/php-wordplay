#!/usr/bin/php
<?php
/**
 * scrabblecheat
 * @version 2014-10-19 15:04
 * @author Steven Hollingsworth <steven.hollingsworth@fresno.gov>
 */

require_once("./WordPlay.php");

$word = (array_key_exists(1,$argv)) ? $argv[1] : "--i";
$interactive = ($word == '--i') ? TRUE : FALSE;

WordPlay::getInstance(); //make sure DB is initialized


if(!$interactive) {
   get($word);
} else {
   echo "Type New character Sequence, press \"Q\" to exit\n";
   while($f = fgets(STDIN)){
      $f = trim($f);
      if (strtolower($f) == 'q') { exit; }
      echo "Getting: $f\n";
      get($f);
      echo "Type New character Sequence, press \"Q\" to exit\n";
   }
}

function get($word) {
   $res = WordPlay::getInstance()->getInstance()->getFuzzySearch($word,50);
   foreach ($res as $score => $words){
      echo "$score: \n";
      echo "   " . implode(" ",$words) . "\n";
   }
}
