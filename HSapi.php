<?php
require_once('dbconn.php');
$player = array();

function player($name){
	global $player;
	if (empty($player[$name])){
	 	$player[$name] = new player($name);
	 }
	return $player[$name];
}

class player{
	function __construct($name){
		global $password;
		global $db;
		$this->name = self::sanitize($name);
		$this->db = $db;
		//$this->new_stats_array(); //generates a stats array with every skill as keys
	}

	public static function sanitize($name){
		$whitelist = array_merge(range(0,9), range('a', 'z'), range('a','z'), array(' '), array('_'), array('-')); // valid character array
		$name = preg_replace("/[^" . preg_quote(implode('',$whitelist), '/') . "]/i", "", $name); //only allows valid characters
		$name = preg_replace(array('/\-/', '/\_/'), ' ', $name); //switches slashes and dashes to spaces
		$name = trim($name); //takes off outside spaces
		$name = ucwords(strtolower($name)); //capitalizes
		return $name;
	}

	public static $skillNames = array("total","attack","defence","strength","hitpoints",
        "ranged","prayer","magic","cooking","woodcutting",
        "fletching","fishing","firemaking","crafting","smithing",
        "mining","herblore","agility","thieving","slayer",
        "farming","runecrafting","hunter","construction");
	public static $skillerSkills = array("total", "cooking","woodcutting",
        "fletching","fishing","firemaking","crafting","smithing",
        "mining","herblore","agility","thieving","slayer",
        "farming","runecrafting","hunter","construction");
	protected $stats_api_updated = false;
	public $name;
	public $stats = array();
	public $stat_changes = array();
	private $playerId = null;
	private $tracking;

	public static function xp_to_lvl($xp) {
	  $points = 0;
    $lvlXP = 0;
    for ($lvl = 1; $lvl < 127; $lvl++) {
      $points += floor($lvl + 300.0 * pow(2.0, $lvl / 7.0));
      $lvlXP = floor($points / 4);
      if($lvlXP > $xp) {
        return $lvl;
      }
    }
    return 126;
	}



	public function load_all_stats(){ //loads every stat from database
		if(!$this->stats_api_updated){
			$sql = <<<SQL
				select datapoint_id 
				from datapoints_tbl 
				where player_id = {$this->player_id()}
				order by date desc 
				limit 1
SQL;
			$result = $this->db->query($sql);
			$id = $result->fetch_assoc()['datapoint_id'];
			$sql = <<<SQL
				SELECT xp, level, virtual, rank
				from datapoint_total_tbl
				where datapoint_id = $id
SQL;
			$result = $this->db->query($sql);
			$row =  $result->fetch_assoc();
			$this->stats['total'] = array();
			$this->stats['total']['xp'] = $row['xp'];
			$this->stats['total']['rank'] = $row['rank'];
			$this->stats['total']['lvl'] = $row['level'];
			$this->stats['total']['vlvl'] = $row['virtual'];
			$sql = <<<SQL
				SELECT skill_name, xp, rank
				FROM datapoint_skills_tbl 
				where datapoint_id = $id
SQL;
			if($result = $this->db->query($sql)){
				while ($row = $result->fetch_assoc()){
					$this->stats[$row['skill_name']] = array();
		       		$this->stats[$row['skill_name']]['xp'] = $row["xp"];
		       		$this->stats[$row['skill_name']]['rank'] = $row["rank"];
		       		$this->stats[$row['skill_name']]['lvl'] = self::xp_to_lvl($row["xp"]);
		    	}
		    	return true; 	
			}
			return false;
		}
		return true;
	}

	

	public function load_stat($stat){ //loads single stat from database
		if(in_array($stat, self::$skillerSkills)){
			if(!isset($this->stats[$stat])){
				if ($stat == 'total'){
					$sql = <<<SQL
						SELECT xp, level, virtual, rank
						FROM datapoint_total_tbl
						where datapoint_id = ( 
							select datapoint_id 
							from datapoints_tbl 
							where player_id = {$this->player_id()}
							order by date desc 
							limit 1
							) 
SQL;
					$this->db->query($sql);
					if ($result = $this->db->query($sql) AND $row = $result->fetch_assoc()){
						$this->stats['total'] = array(
							'xp' => $row['xp'],
							'lvl' => $row['level'],
							'vlvl' => $row['virtual'],
							'rank' => $row['rank']
							);
						return true;
					}
				}
				else{
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
					$this->stat[$stat] = array(
						'xp' => $row["skill_xp"],
						'rank' => $row["skill_rank"],
						'lvl' => $row["skill_level"]
						);		       		
		       		if(isset($this->stats[$stat]['xp']) and isset($this->stats[$stat]['rank']) and isset($this->stats[$stat]['lvl'])){
		    			$this->stats_api_updated = false;
		    			return true;
	    			}
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
				$rawStats = preg_split('/\s+/', $rawStr);
				$stats = array();
				$i = 0;
				$stats['total'] = array();
				$stats['total']['rank'] = explode(',', $rawStats[$i++])[0];
				$stats['total']['lvl'] = 16;
				$stats['total']['vlvl'] = 16;
				$stats['total']['xp'] = 1154;
				foreach (array_slice(self::$skillNames, 1) as $skill){
					$stats[$skill] = array();
					$ph = explode(',', $rawStats[$i++]);
					$stats[$skill]['rank'] = $ph[0];
					$stats[$skill]['lvl'] = self::xp_to_lvl($ph[2]);
					$stats[$skill]['xp'] = $ph[2];
 				}
 				foreach (array_slice(self::$skillerSkills, 1) as $skill){
 					$stats['total']['vlvl'] += $stats[$skill]['lvl'];
					$stats['total']['lvl'] += min(99, $stats[$skill]['lvl']);
					$stats['total']['xp'] += $stats[$skill]['xp'];
 				}
				$this->stats = $stats;	
				return true;
			}
			return false;
		}
		return true;
	}

	public function gen_json_string($skillId){
		$skill = self::$skillerSkills[$skillId];
		$json = array();
		$json['cols'] = array(
	    array(
	      "label"=>"year",
	      "type"=>"date",
	    ),
	    array(
	      "label"=>"Experience",
	      "type"=>"number",
	    )
  	);
  	$json['rows'] = array();
  	if ($skill == 'total'){
			$sql = <<<SQL
				SELECT CAST(date AS DATE) as date, xp
				from (
				    select datapoint_id, date
				    from datapoints_tbl
				    where player_id = {$this->player_id()}
				    ) as a
				inner join (
				    select datapoint_id, xp
				    from datapoint_total_tbl
				    ) 
				as b
				ON a.datapoint_id=b.datapoint_id
				group by CAST(date AS DATE)
				order by date
SQL;
  	}
  	else{
  		$sql = <<<SQL
				SELECT CAST(date AS DATE) as date, xp
				from (
				    select datapoint_id, date
				    from datapoints_tbl
				    where player_id = {$this->player_id()}
				    ) as a
				inner join (
				    select datapoint_id, xp
				    from datapoint_skills_tbl
				    where skill_name = '$skill'
				    ) 
				as b
				ON a.datapoint_id=b.datapoint_id
				group by CAST(date AS DATE)
				order by date
SQL;
  	}
		$result = $this->db->query($sql);
		while ($row = $result->fetch_assoc()){
			$date = explode('-',$row['date']);
			$xp = $row['xp'];
			$json['rows'][]= array(
	      "c" => array(
	        array(
	          "v" => "Date(".$date[0].", ".$date[1].", ".$date[2].")"
	        ),
	        array(
	          "v" => $xp
	        )
	      )
	    );
		}
		return json_encode($json);
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

	public function has_changed(){
		$this->get_api_data();
		$sql = <<<SQL
			SELECT date, xp
			FROM 
				(select *
			     from datapoints_tbl
			     where player_id = (
			         select player_id
			         from players_tbl
			         where player_name = '$this->name'
			         )
			     order by date desc
			     limit 2
			     )
			as d
			inner join datapoint_total_tbl as dt
			  on d.datapoint_id=dt.datapoint_id
			order by d.date
			desc
SQL;
		$result = $this->db->query($sql);
		$result->fetch_assoc();
		$row = $result->fetch_assoc();
		$xp = $row['xp'];
		if ($this->stats['total']['xp'] == $row['xp']){
			return false;
		}
		return true;
	}

	private function delete_last(){
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


	public function update(){ //creates a datapoint for a player
		if ($this->is_entry() or $this->create_entry()){
			//gets data from api
			if($this->get_api_data() and $this->cb() < 4){
				if(!$this->is_tracking() or !$this->has_changed()){ // or (isset($result) AND $row = $result->fetch_assoc() AND $row['results'] > 1)){
					$this->delete_last();
				}
				$sql = <<<SQL
					INSERT INTO datapoints_tbl (player_id, date, valid)
					VALUES ((SELECT player_id FROM players_tbl WHERE player_name = "$this->name"), UTC_TIMESTAMP(), 1)
SQL;
				$this->db->query($sql);

				$datapoint_id = $this->db->insert_id;
				$sql = "
					INSERT INTO datapoint_total_tbl (datapoint_id, xp, level, virtual, rank)
					VALUES ($datapoint_id, ".$this->stats['total']['xp'].", ".$this->stats['total']['lvl'].", ".$this->stats['total']['vlvl'].", ".$this->stats['total']['rank']." )
					";
				$this->db->query($sql);
				$sql = <<<SQL
					INSERT INTO datapoint_skills_tbl (datapoint_id, skill_name, xp, rank)
					VALUES
SQL;
				foreach (array_slice(self::$skillerSkills, 1) as $skill){
					$sql .= " (".$datapoint_id.", '".$skill."', ".$this->stats[$skill]['xp'].", ".$this->stats[$skill]['rank']."),";
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
		return false;
	}

	public function load_gains($period, $skill){
		if ($this->is_tracking() and in_array($skill, self::$skillerSkills) and !isset($this->stat_changes[$skill][$period])){
			if (!isset($this->stat_changes[$skill])){
				$stat_changes[$skill] = array();
			}
			if ($period == 'day'){
				$modPeriod = '1 day';
			}
			elseif ($period == 'week'){
				$modPeriod = '7 day';
			}
			else{
				return false;
			}
			if ($skill == 'total'){
				$tbl = 'datapoint_total_tbl';
				$skillMod = '';
			}
			else{
				$tbl = 'datapoint_skills_tbl';
				$skillMod = 'AND skill_name = "'.$skill.'"';
			}
			$sql = <<<SQL
			SELECT (
		    SELECT xp
		    from $tbl
		    where datapoint_id = (
		        SELECT datapoint_id
		        FROM datapoints_tbl
		        WHERE player_id = {$this->player_id()}
		        AND date >= utc_timestamp() - INTERVAL $modPeriod
		        order by date desc
		        limit 1
		    		)
				$skillMod
				)-(
		    SELECT xp
		    from $tbl
		    where datapoint_id = (
		        SELECT datapoint_id
		        FROM datapoints_tbl 
		        WHERE player_id = {$this->player_id()}
		        AND date >= utc_timestamp() - INTERVAL $modPeriod
		        order by date asc
		        limit 1
	    	)
				$skillMod
			)
			as chnge
SQL;
			$result = $this->db->query($sql);
			if ($row = $result->fetch_assoc()){
				$this->stat_changes[$skill][$period] = $row['chnge'];
				return true;
			}
		}
		return false;
	}

	public function day($skill){
		if (isset($this->stat_changes[$skill]['day']) or $this->load_gains('day', $skill)){
			return $this->stat_changes[$skill]['day'];
		}
	}

	public function week($skill){
		if (isset($this->stat_changes[$skill]['week']) or $this->load_gains('week', $skill)){
			return $this->stat_changes[$skill]['week'];
		}
	}

	public function name(){
		return $this->name;
	}

	public function is_tracking(){
		if ($this->is_entry() AND !isset($this->tracking)){
			$sql = <<<SQL
				SELECT tracking
				FROM players_tbl
				WHERE player_name = '$this->name'
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
		if($result != null AND $row = $result->fetch_assoc() AND $row['valid'] == 1){
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

	private function skill_info($type, $stat){
		if (in_array($stat, self::$skillNames) and //only runs if array key $stat exists to prevent entering incorrect stat name
			(isset($this->stats[$stat][$type]) or $this->load_stat($stat) or $this->get_api_data()
			)){ //tries to load stat from database, if can't loads it from rs api
				return $this->stats[$stat][$type];
			}
		return null;
	}

	public function lvl($stat){ //returns player lvl in stat
		if ($stat == 'total'){
			return $this->skill_info('lvl', $stat);
		}
		return min($this->skill_info('lvl', $stat), 99);
	}

	public function virtual($stat){
		if ($stat == 'total'){
			return $this->skill_info('vlvl', $stat);
		}
		return $this->skill_info('lvl', $stat);
	}

	public function xp($stat){ //returns player xp in stat
		return $this->skill_info('xp', $stat);
	}

	public function rank($stat){ //returns player rank in stat
		return $this->skill_info('rank', $stat);
	}

	public function last_updated(){
		if ($this->is_valid()){
			$sql = <<<SQL
			SELECT timediff(utc_timestamp(), (
				select max(date) 
				from datapoints_tbl 
				where player_id = {$this->player_id()}
			))
			as last_updated
SQL;
			if($result = $this->db->query($sql)){
				if($row = $result->fetch_assoc()){
					return $row['last_updated'];
				}
			}
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
?>