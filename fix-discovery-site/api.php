<?php

require_once('./config.php');

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 'Off');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
// define('CANDIDATES_FILE', __DIR__ . '/data/candidates.json');

$callback = get_request('callback', '');
$action = get_request('action', '');
$num = max(1, min(10, intval(get_request('num',1))));
$lang = get_request('lang', 'en');

$out = [];

if($action == 'desc'){
    $out = [
        'label' => [
            'en' => 'Pick the Discovery site'
        ],
        'description' => [
	    'en' => 'Astronomical objects should have only ONE discovery site. Help choose the correct one!',
        ],
        'icon' => 'https://upload.wikimedia.org/wikipedia/commons/0/00/Crab_Nebula.jpg',
        'instructions' => [
            'en' => "Each item shows an astronomical object with multiple discovery sites listed. This violates the single-value constraint for P65. Review the options and click the button for the correct discovery site. Observatories carry more weightage. Click 'Skip' if you are unsure."
        ],
    ];
}
else if ($action == 'tiles'){
    $db = connectDB('discovery_site_violations');

    $out['tiles'] = [];
    $selected_ids = [];

    $attempts = 0;
    while(count($out['tiles']) < $num && $attempts < 3){
        $attempts++;

        $r = mt_rand() / mt_getrandmax();
        $sql = "SELECT * FROM candidates  WHERE status IS NULL AND random >= $r";

        if(!empty($selected_ids)){
            $sql .= "AND id NOT IN (" . implode(',',$selected_ids) . ")";
        }

        $sql .= " ORDER BY random LIMIT " . ($num * 2);

        $result = $db->query($sql);

        if(!$result){
            $out['error'] = 'Database error: ' . $db->error;
            break;
        }
        while($row = $result->fetch_object()){
            $selected_ids[] = $row->id;

            $candidate = json_decode($row->data, true);
            if($candidate){
                $tile = buildTile($candidate, $row->id);
                if($tile !== null){
                    $out['tiles'][] = $tile;
                }
            }
            if(count($out['tiles']) >= $num) break;
        }
        if($result->num_rows == 0) break;
    }
    $count_result = $db->query("SELECT COUNT(*) as cnt FROM candidates WHERE status IS NULL");
    if($count_result && $row = $count_result->fetch_object()){
        $out['remaining'] = intval($row->cnt);
        if ($out['remaining']<10){
            $out['low'] = 1;
        }
    }
}

else if ($action == 'log_action'){
    // $out['status'] = 'OK';
    // $out['message'] = 'Action logged';
    // need to write the rest, checking for now
    $db = connectDB('discovery_site_violations');

    $user = get_request('user', '');
    $tile = intval(get_request('tile', 0));
    $decision = get_request('decision', '');

    if($decision == 'skip'){
        $out['status'] = 'OK';
        $out['message'] = 'Skipped';
    }
    else if($tile > 0 && $user !== ''){
        $uid = getUID($db, $user);
        $ts = date('YmdHis');
        $safe_decision = $db->real_escape_string($decision);

        $status = (strpos($decision, 'keep_') === 0) ? 'FIXED' : 'OTHER';

        $sql = "UPDATE candidates
                SET status='$status',
                    decision='$safe_decision',
                    user='$uid',
                    timestamp='$ts'
                WHERE id=$tile AND status IS NULL";
        
        if($db->query($sql) && $db->affected_rows > 0){
            $sql = "UPDATE scores SET fixes = fixes + 1 WHERE user = $uid";
            $db->query($sql);
            
            $out['status'] = 'OK';
            $out['message'] = 'Logged successfully';
        }else{
            $out['status'] = 'OK';
            $out['message'] = 'Already processed or not found';
        }
    }
    else{
        $out['status'] = 'ERROR';
        $out['message'] = 'Invalid parameters';
    }
}

else{
    $out['error'] = 'Unknown action';
}

$json_out = json_encode($out, JSON_UNESCAPED_UNICODE);

if ($callback !== ''){
    $callback = preg_replace('/[^a-zA-Z0-9_]/','',$callback);
    print $callback . '(' . $json_out . ')';
}else{
    print $json_out;
}

function buildTile($candidate, $db_id){
    if (!isset($candidate['item_q']) || 
        !isset($candidate['sites']) || 
        count($candidate['sites']) < 2) {
        return null;
    }
    $sections = [];
    // item section
    $sections[] = [
        'type' => 'item',
        'q' => $candidate['item_q']
    ];
    
    $sections[] = [
        'type' => 'text',
        'title' => 'Single-Value Constraint Violation',
        'text' => 'This astronomical object has ' . count($candidate['sites']) . 
                  ' discovery sites (P65), but should only have ONE.' .
                  "\n\nChoose the correct site. Others will be deprecated."
    ];
    
    $site_list = '';
    foreach ($candidate['sites'] as $site) {
        $ref_marker = !empty($site['has_reference']) ? ' (has reference)' : '';
        $site_list .= '* ' . $site['site_label'] . 
                      ' (' . $site['site_q'] . ')' . 
                      $ref_marker . "\n";
    }
    
    $sections[] = [
        'type' => 'text',
        'title' => 'Current P65 values:',
        'text' => $site_list
    ];
    
    $buttons = [];
    
    foreach ($candidate['sites'] as $site) {
        $claims_to_deprecate = [];
        
        foreach ($candidate['sites'] as $other) {
            if ($other['statement_guid'] !== $site['statement_guid']) {
                $claims_to_deprecate[] = [
                    'id' => $other['statement_guid'],
                    'type' => 'statement',
                    'rank' => 'deprecated',
                    'mainsnak' => [
                        'snaktype' => 'value',
                        'property' => 'P65',
                        'datavalue' => [
                            'type' => 'wikibase-entityid',
                            'value' => [
                                'entity-type' => 'item',
                                'numeric-id' => intval($other['site_numberic_id'] ?? 
                                    preg_replace('/\D/', '', $other['site_q']))
                            ]
                        ]
                    ]
                ];
            }
        }
        
        if (!empty($claims_to_deprecate)) {
            $label = $site['site_label'];
            
            $data = ['claims' => []];
            foreach ($claims_to_deprecate as $claim) {
                $data['claims']['P65'][] = $claim;
            }
            
            // wbsetclaim doesnt work when theres more than one to deprecate
            $buttons[] = [
                'type' => 'green',
                'decision' => 'keep_' . $site['site_q'],
                'label' => $label,
                'api_action' => [
                    'action' => 'wbeditentity',
                    'id' => $candidate['item_q'],
                    'data' => json_encode($data),
                    'summary' => 'Deprecated duplicate discovery sites via Wikidata Game'
                ]
            ];
        }
    }
    
    // Skip button
    $buttons[] = [
        'type' => 'white',
        'decision' => 'skip',
        'label' => 'Skip'
    ];
    
    return [
        'id' => $db_id,
        'sections' => $sections,
        'controls' => [
            [
                'type' => 'buttons',
                'entries' => $buttons
            ]
        ]
    ];
}
?>