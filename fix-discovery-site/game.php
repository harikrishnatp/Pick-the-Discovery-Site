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
        'description' => ['Astronomical objects should have only ONE discovery site. Help choose the correct one!',
        ],
        'icon' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/f/f7/Nuvola_apps_kstars.png/120px-Nuvola_apps_kstars.png',
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

        if(!result){
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
}

else{
    $out['error'] = 'Unknown action';
}

if ($callback !== ''){
    $callback = preg_replace('/[^a-zA-Z0-9_]/','',$callback);
    print $callback . '(' . json_encode($out) . ')';
}else{
    print json_encode($out);
}

function buildTile($candidate){
    $sections = array();
    $sections[] = array(
        'type' => 'item',
        'q' => $candidate['item_q'],
        'item_label' => $candidate['item_label'],
        'description' => $candidate['item_description'],
    );
    $sections[] = array(
        'type' => 'text',
        'title' => 'Multiple Discovery Sites Found',
        'text' => 'This astronomical object has ' . count($candidate['sites']) . ' ' .
                  'discovery sites listed, but should only have ONE. ' .
                  'Please choose the correct site. The others will be deprecated.'
    );
    $site_list = '';
    foreach($candidate['sites'] as $site){
        $ref_indicator = $site['has_reference'] ? ' (Yes)' : '';
        $site_list .= '- ' . $site['site_label'] . $ref_indicator . "\n";
    }
    $sections[] = array(
        'type' => 'text',
        'title' => 'Current P65 values:',
        'text' => $site_list . "\n(Yes = has reference)"
    );

    $buttons = array();

    foreach($candidate['sites'] as $site){
        // keep the clicked button and others are deprecated
        $deprecate_actions = array();
        foreach($candidate['sites'] as $other_site){
            if($other_site['statement_guid'] === $site['statement_guid']){
                continue;
            }
            $deprecate_actions[] = array(
                'action' => 'wbsetclaim',
                'claim' => array(
                    // statement guid to identify which statement to deprecate
                    'id' => $other_site['statement_guid'],
                    'type' => 'statement',
                    'rank' => 'deprecated',
                    'mainsnak' => array(
                        'property' => 'P65',
                        'snaktype' => 'value',
                        'datavalue' => array(
                            'type' => 'wikibase-entityid',
                            'value' => array(
                                'entity-type' => 'item',
                                'numeric-id' => $other_site['site_numberic_id']
                            )
                        )
                    )
                )
            );
         }
         $label = $site['site_label'];
         if ($site['has_reference']){
            $label .= ' (Yes)';
         }
         $buttons[] = array(
            'type' => 'green',
            'decision' => 'keep_' . $site['site_q'],
            'label' => $label,
            'api_action' => $deprecate_actions
         );
    }
    $buttons[] = array(
        'type' => 'white',
        'decision' => 'skip',
        'label' => 'Skip (not sure)'
        //have to add api_action to see on wikidata
    );

    $tile = array(
        'id' => $candidate['item_q'],
        'sections' => $sections,
        'controls' => array(
            array(
                'type' => 'buttons',
                'entries' => $buttons
            )
        )
    );

    return $tile;
}
?>