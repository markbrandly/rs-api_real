<?php
include('variables.php');
$db = new mysqli('localhost', 'root', $password, 'skiller_scores');
$player = array();

function player($name){
	global $player;
	if (empty($player[$name])){
	 	$player[$name] = new player($name);
	 }
	return $player[$name];
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

class player {
	function __construct($name){
		$this->name = $name;
		$this->db = new mysqli('localhost', 'root', global $password, 'skiller_scores'); //connects to 
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
			SELECT skill_name, skill_xp, skill_rank
			FROM datapoint_parts_tbl 
			where datapoint_id = ( 
				select datapoint_id 
				from datapoints_tbl 
				where player_id = (
					select player_id
					from players_tbl
					where player_name = "$this->name"
					) 
				order by date desc 
				limit 1
				)
SQL;
		if($result = $this->db->query($sql)){
			while ($row = $result->fetch_assoc()){
	       		$this->stats[$row['skill_name']]['xp'] = $row["skill_xp"];
	       		$this->stats[$row['skill_name']]['rank'] = $row["skill_rank"];
	       		$this->stats[$row['skill_name']]['lvl'] = xp_to_lvl($row["skill_xp"]);
	    	}
	    	return true;
			$this->stats_api_updated = false;	    	
		}
		return false;
	}

	public function load_stat($stat){ //loads single stat from database
		if (array_key_exists($stat, $this->stats) 
		and (!isset($this->stats[$stat]['lvl'])
			or !isset($this->stats[$stat]['xp'])
			or !isset($this->stats[$stat]['rank'])
		)){
			$sql = <<<SQL
				SELECT skill_xp, skill_rank
				FROM datapoint_parts_tbl 
				where datapoint_id = ( 
					select datapoint_id 
					from datapoints_tbl 
					where player_id = (
						select player_id
						from players_tbl
						where player_name = "$this->name"
						) 
					order by date desc 
					limit 1
					)
				AND skill_name = '$stat'
SQL;
			$result = $this->db->query($sql);
			if ($row = $result->fetch_assoc()){
	       		$this->stats[$stat]['xp'] = $row["skill_xp"];
	       		$this->stats[$stat]['rank'] = $row["skill_rank"];
	       		$this->stats[$stat]['lvl'] = xp_to_lvl($row["skill_xp"]);
	    	}
	    	if(isset($this->stats[$stat]['xp']) and isset($this->stats[$stat]['rank'])){
	    		$this->stats_api_updated = false;
	    		return true;
	    	}
		}
		return false;
	}

	public function get_api_data(){ //grabs api data from rs api and updates $skills array with the information. returns true if already updated
		if (!$this->stats_api_updated){
			$url ="http://services.runescape.com/m=hiscore_oldschool/index_lite.ws?player=".$this->name;
			if ($rawStr = file_get_contents($url)){
				#$rawStr = '66451,1211,11284756 191297,52,130985 160912,45,61846 214316,52,132805 77023,75,1291197 20666,85,3291695 101148,46,70766 68599,71,851256 58481,70,748115 147924,55,172688 165556,33,19549 145325,53,139543 49785,50,105445 39390,61,313953 119253,43,55296 43675,60,278262 84555,39,36314 54331,55,167667 46144,53,147725 133127,39,35299 61174,35,24304 1198,84,3058746 39358,54,151300 124508,1,0 72054,2 -1,-1 -1,-1';
				$rawStats = preg_split('/\s+/', $rawStr);
				$stats = array();
				$i = 0;
				foreach ($this->skillNames as $skill){
					$stats[$skill] = array();
					$stats[$skill]['rank'] = explode(',', $rawStats[$i])[0];
					$stats[$skill]['lvl'] = explode(',', $rawStats[$i])[1];
					$stats[$skill]['xp'] = explode(',', $rawStats[$i++])[2];
				}
				$this->stats = $stats;
				$this->stats_api_updated = true;
				return true;
			}
			return false;
		}
		return true;
	}

	public function update(){ //creates a datapoint for a player
		if($this->get_api_data()){
			$sql = <<<SQL
				INSERT INTO datapoints_tbl (player_id, date, current)
				VALUES ((SELECT player_id FROM players_tbl WHERE player_name = "$this->name"), UTC_TIMESTAMP(), 1)
SQL;
			$this->db->query($sql);
			$datapoint_id = $this->db->insert_id;
			$sql = <<<SQL
				INSERT INTO datapoint_parts_tbl (datapoint_id, skill_name, skill_xp, skill_rank)
				VALUES
SQL;
			foreach ($this->stats as $skillName=>$skillInfo){
				$sql .= " (".$datapoint_id.", '".$skillName."', ".$skillInfo['xp'].", ".$skillInfo['rank']."),";
			}
			$sql = rtrim($sql, ",").";";
			$this->db->query($sql);			
		}
	 }

	public function create_entry(){ //creates a player entry if cb < 4 (definition of a skiller)
		if(!$this->is_entry() and $this->get_api_data() and $this->cb()<4){
			$sql = <<<SQL
				INSERT INTO players_tbl (player_name)
				VALUES ('$this->name')
SQL;
			$result = $this->db->query($sql);
			if ($result){
				return true;
			}
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
		return false;
	}

	public function xp($stat){ //returns player xp in stat
		if (array_key_exists($stat, $this->stats) and //only runs if array key $stat exists to prevent entering incorrect stat name
			(isset($this->stats[$stat]['xp']) or $this->load_stat($stat) or $this->get_api_data())){ //tries to load stat from database, if can't loads it from rs api
				return $this->stats[$stat]['xp'];
			}
		return false;
	}

	public function rank($stat){ //returns player rank in stat
		if (array_key_exists($stat, $this->stats) and //only runs if array key $stat exists to prevent entering incorrect stat name
			(isset($this->stats[$stat]['rank']) or $this->load_stat($stat) or $this->get_api_data())){ //tries to load stat from database, if can't loads it from rs api
				return $this->stats[$stat]['rank'];
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
			return "null";
		}
	}
}

?>