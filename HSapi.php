<?php
include('variables.php');
$player = array();


function player($name){
	global $player;
	if (empty($player[$name])){
	 	$player[$name] = new player($name);
	 }
	return $player[$name];
}

$skillNames = array("total","attack","defence","strength","hitpoints",
        "ranged","prayer","magic","cooking","woodcutting",
        "fletching","fishing","firemaking","crafting","smithing",
        "mining","herblore","agility","thieving","slayer",
        "farming","runecrafting","hunter","construction");

function sanitize($name){
	$whitelist = array_merge(range(0,9), range('a', 'z'), range('a','z'), array(' '), array('_'), array('-')); // valid character array
	$name = preg_replace("/[^" . preg_quote(implode('',$whitelist), '/') . "]/i", "", $name); //only allows valid characters
	$name = preg_replace(array('/\-/', '/\_/'), ' ', $name); //switches slashes and dashes to spaces
	$name = trim($name); //takes off outside spaces
	$name = ucwords(strtolower($name)); //capitalizes
	return $name;
}

function xp_to_lvl($xp) {
    $points = 0;
    $lvlXP = 0;
    for ($lvl = 1; $lvl < 100; $lvl++) {
        $points += floor($lvl + 300.0 * pow(2.0, $lvl / 7.0));
        $lvlXP = floor($points / 4);
        if($lvlXP > $xp) {
            return $lvl;
        }
    }
    return 99;
}

class player{
	function __construct($name){
		global $password;
		global $db;
		$this->name = sanitize($name);
		if (!isset($db)){
			$db = new mysqli('localhost', 'root', $password, 'skiller_scores');
		}
		$this->db = $db;
		$this->new_stats_array(); //generates a stats array with every skill as keys --might be removed later--
	}

	protected $skillNames = array("total","attack","defence","strength","hitpoints",
        "ranged","prayer","magic","cooking","woodcutting",
        "fletching","fishing","firemaking","crafting","smithing",
        "mining","herblore","agility","thieving","slayer",
        "farming","runecrafting","hunter","construction");
	protected $stats_api_updated = false;
	public $name;
	public $stats;
	private $playerId = null;
	private $tracking;

	private function new_stats_array(){ //creates an array with every stat name
		$stats = array();
		foreach ($this->skillNames as $skill){
			$stats[$skill] = array();
			$stats[$skill]['rank'] = null;
			$stats[$skill]['lvl'] = null;
			$stats[$skill]['xp'] = null;
		}
		$this->stats = $stats;
	}

	public function load_all_stats(){ //loads every stat from database
		$sql = <<<SQL
			SELECT skill_name, skill_xp, skill_rank, skill_level
			FROM datapoint_parts_tbl 
			where datapoint_id = ( 
				select datapoint_id 
				from datapoints_tbl 
				where player_id = {$this->player_id()}
				order by date desc 
				limit 1
				)
SQL;
		if($result = $this->db->query($sql)){
			while ($row = $result->fetch_assoc()){
	       		$this->stats[$row['skill_name']]['xp'] = $row["skill_xp"];
	       		$this->stats[$row['skill_name']]['rank'] = $row["skill_rank"];
	       		$this->stats[$row['skill_name']]['lvl'] = $row["skill_level"];
	    	}
	    	return true;
			$this->stats_api_updated = false;	    	
		}
		return false;
	}

	

	public function load_stat($stat){ //loads single stat from database
		if(array_key_exists($stat, $this->stats)){
			if(!isset($this->stats[$stat]['lvl'])
			or !isset($this->stats[$stat]['xp'])
			or !isset($this->stats[$stat]['rank'])){
					$sql = <<<SQL
						SELECT skill_xp, skill_rank, skill_level
						FROM datapoint_parts_tbl 
						where datapoint_id = ( 
							select datapoint_id 
							from datapoints_tbl 
							where player_id = {$this->player_id()}
							order by date desc 
							limit 1
							)
						AND skill_name = '$stat'
SQL;
				if ($result = $this->db->query($sql) AND $row = $result->fetch_assoc()){
		       		$this->stats[$stat]['xp'] = $row["skill_xp"];
		       		$this->stats[$stat]['rank'] = $row["skill_rank"];
		       		$this->stats[$stat]['lvl'] = $row["skill_level"];
		       		if(isset($this->stats[$stat]['xp']) and isset($this->stats[$stat]['rank']) and isset($this->stats[$stat]['lvl'])){
		    			$this->stats_api_updated = false;
		    			return true;
	    			}
	    		}
	    		return false;
	    	}
	    	return true;
		}
		return false;
	}

	public function get_api_data(){ //grabs api data from rs api and updates $skills array with the information. returns true if already updated
		if (!$this->stats_api_updated){
			$this->stats_api_updated = true;
			$ctx = stream_context_create(array(
			    'http' => array (
			        'ignore_errors' => TRUE
			     )
			));
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'http://services.runescape.com/m=hiscore_oldschool/index_lite.ws?player='.$this->name());
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$rawStr = curl_exec($ch);
			if (strlen($rawStr) == strlen(strip_tags($rawStr))){
				//$rawStr = '66451,1211,11284756 191297,52,130985 160912,45,61846 214316,52,132805 77023,75,1291197 20666,85,3291695 101148,46,70766 68599,71,851256 58481,70,748115 147924,55,172688 165556,33,19549 145325,53,139543 49785,50,105445 39390,61,313953 119253,43,55296 43675,60,278262 84555,39,36314 54331,55,167667 46144,53,147725 133127,39,35299 61174,35,24304 1198,84,3058746 39358,54,151300 124508,1,0 72054,2 -1,-1 -1,-1';
				$rawStats = preg_split('/\s+/', $rawStr);
				$stats = array();
				$i = 0;
				foreach ($this->skillNames as $skill){
					$stats[$skill] = array();
					$ph = explode(',', $rawStats[$i++]);
					$stats[$skill]['rank'] = $ph[0];
					$stats[$skill]['lvl'] = $ph[1];
					$stats[$skill]['xp'] = $ph[2];
				}
				$this->stats = $stats;	
				return true;
			}
			return false;
		}
		return true;
	}

	public function create_entry(){ //creates a player entry if cb < 4 (definition of a skiller)
		if(!$this->is_entry() and $this->get_api_data() and $this->cb()<4){
			$sql = <<<SQL
				INSERT INTO players_tbl (player_name, tracking)
				VALUES ('$this->name', 0)
SQL;
			$result = $this->db->query($sql);
			if ($result){
				return true;
			}
		}
		return false;
	}

	public function update(){ //creates a datapoint for a player
		if ($this->is_entry() or $this->create_entry()){
			//gets data from api
			if($this->get_api_data()){
				//updates database with valid entry if skiller
				if($this->cb() < 4){
					$sql = <<<SQL
						SELECT count(*) 
						AS results 
						FROM datapoints_tbl 
						WHERE player_id = {$this->player_id()}
						AND cast(date as date) = utc_date()
SQL;
					$result = $this->db->query($sql);
					if(!$this->is_tracking() or (isset($result) AND $row = $result->fetch_assoc() AND $row['results'] > 1)){
						$sql = <<<SQL
							DELETE 
							FROM datapoint_parts_tbl
							WHERE datapoint_id =(
							    SELECT datapoint_id
							    FROM datapoints_tbl
							    WHERE player_id = {$this->player_id()}
							   	order by date
							    limit 1
							)
SQL;
						if ($result = $this->db->query($sql)){
							$sql = <<<SQL
							DELETE
							FROM datapoints_tbl
							WHERE player_id = {$this->player_id()}
							order by date
							desc
							limit 1;				
SQL;
							$this->db->query($sql);
						}
					}
					$sql = <<<SQL
						INSERT INTO datapoints_tbl (player_id, date, valid)
						VALUES ((SELECT player_id FROM players_tbl WHERE player_name = "$this->name"), UTC_TIMESTAMP(), 1)
SQL;
					$this->db->query($sql);
					$datapoint_id = $this->db->insert_id;
					$sql = <<<SQL
						INSERT INTO datapoint_parts_tbl (datapoint_id, skill_name, skill_xp, skill_rank, skill_level)
						VALUES
SQL;
					foreach ($this->stats as $skillName=>$skillInfo){
						$sql .= " (".$datapoint_id.", '".$skillName."', ".$skillInfo['xp'].", ".$skillInfo['rank'].", ".$skillInfo['lvl']."),";
					}
					$sql = rtrim($sql, ",").";";
					$this->db->query($sql);
					return true;		
				}
				//if not a skiller, checks last db entry and if it isn't set or if it's valid it creates an invalid entry
				elseif($this->is_valid()){
					$sql = <<<SQL
						INSERT INTO datapoints_tbl (player_id, date, valid)
						VALUES ((SELECT player_id FROM players_tbl WHERE player_name = "$this->name"), UTC_TIMESTAMP(), 0)
SQL;
					$this->db->query($sql);				
				}
			}
		}
		return false;
	 }


	public function name(){
		return $this->name;
	}

	public function is_tracking(){
		if ($this->is_entry() AND !isset($this->tracking)){
			$sql = <<<SQL
				SELECT tracking
				FROM players_tbl
				WHERE player_name = $this->name
				LIMIT 1
SQL;
			if($result = $this->db->query($sql)){
				$row = $result->fetch_assoc();
				if ($row['tracking'] == 1){
					$this->tracking = true;
				}
			}

		}
		return $this->tracking;
	}

	public function player_id(){
		if (!isset($this->playerId)){
			$sql = <<<SQL
				select player_id
				from players_tbl
				where player_name = "$this->name"
				limit 1
SQL;
			$result = $this->db->query($sql);
			if($row = $result->fetch_assoc()){
				$this->playerId = $row['player_id'];
			}
		}
		return $this->playerId;
	}

	

	public function is_valid(){
		$sql = <<<SQL
			SELECT valid
			FROM datapoints_tbl 
			WHERE player_id = {$this->player_id()}
			ORDER BY date
			LIMIT 1
SQL;
		$result = $this->db->query($sql);
		if(!$row = $result->fetch_assoc() or $row['valid'] == 1){
			return true;
		}
		return false;
	}

	public function is_entry(){ //checks if there's a row with player_name in players tbl
		$sql=<<<SQL
				SELECT player_id
				FROM players_tbl
				WHERE player_name = '$this->name'
SQL;
		$result = $this->db->query($sql);
		if($row = $result->fetch_assoc() and $row['player_id']>0){
			return true;
		}
		return false;
	}

	public function lvl($stat){ //returns player lvl in stat
		if (array_key_exists($stat, $this->stats) and //only runs if array key $stat exists to prevent entering incorrect stat name
			(isset($this->stats[$stat]['lvl']) or $this->load_stat($stat) or $this->get_api_data())){ //tries to load stat from database, if can't loads it from rs api
				return $this->stats[$stat]['lvl'];
			}
		return null;
	}

	public function xp($stat){ //returns player xp in stat
		if (array_key_exists($stat, $this->stats) and //only runs if array key $stat exists to prevent entering incorrect stat name
			(isset($this->stats[$stat]['xp']) or $this->load_stat($stat) or $this->get_api_data())){ //tries to load stat from database, if can't loads it from rs api
				return $this->stats[$stat]['xp'];
			}
		return null;
	}

	public function rank($stat){ //returns player rank in stat
		if (array_key_exists($stat, $this->stats) and //only runs if array key $stat exists to prevent entering incorrect stat name
			(isset($this->stats[$stat]['rank']) or $this->load_stat($stat) or $this->get_api_data())){ //tries to load stat from database, if can't loads it from rs api
				return $this->stats[$stat]['rank'];
			}
		return null;
	}

	public function last_updated(){
		$sql = <<<SQL
			SELECT timediff(utc_timestamp(), (
				select max(date) 
				from datapoints_tbl 
				where player_id = {$this->player_id()}
			))
			as last_updated
SQL;
		$result = $this->db->query($sql);
		if($row = $result->fetch_assoc()){
			return $row['last_updated'];
		}
		return false;
	}

	public function cb(){ //returns player combat level
		if ($hits = $this->lvl('hitpoints')
		and $str = $this->lvl('strength') 
		and $att = $this->lvl('attack')
		and $def = $this->lvl('defence')
		and $mage = $this->lvl('magic')
		and $rng = $this->lvl('ranged')
		and $pray = $this->lvl('prayer')){
		 	$baseCb = 0.25*($def + $hits + floor($pray/2.0));
		 	$meleeCb = 0.325*($att + $str) + $baseCb;
		 	$rangedCb = 0.325*(floor($rng*0.5) + $rng) + $baseCb;
		 	$mageCb = 0.325*(floor($mage*0.5) + $mage) + $baseCb;
		 	return max($meleeCb, $mageCb, $rangedCb);
		}
		else{
			return false;
		}
	}
}

echo player('bea5')->is_tracking();

?>