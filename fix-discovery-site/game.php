<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
define('CANDIDATES_FILE', __DIR__ . '/data/candidates.json');

$callback = isset($_REQUEST['callback']) ? $_REQUEST['callback'] : '';
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$num = isset($_REQUEST['num']) ? intval($_REQUEST['num']) : 1;
$out = array();

if($action == 'desc'){
    $out = array(
        'label' => array(
            'en' => 'Pick the Discovery site'
        ),
        'description' => array('Astronomical objects should have only ONE discovery site. Help choose the correct one!',
        ),
        'icon' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/e5/25_Images_to_Celebrate_NASA%E2%80%99s_Chandra_25th_Anniversary-_Crab_Nebula_%2853893798390%29.jpg/960px-25_Images_to_Celebrate_NASA%E2%80%99s_Chandra_25th_Anniversary-_Crab_Nebula_%2853893798390%29.jpg?20240810135150',
        'instructions' => 'Each item shows an astronomical object with multiple discovery sites listed. This violates the single-value constraint for P65. Review the options and click the button for the correct discovery site. Observatories carry more weightage. Click "Skip" if you are unsure.'
    );
}
else if ($action == 'tiles'){
    if(!file_exists(CANDIDATES_FILE)) {
        $out['error'] = 'Candidates file not found';
        $out['tiles'] = array();
    }else{
        $json = file_get_contents(CANDIDATES_FILE);
        $data = json_decode($json, true);

        if($data === null){
            $out['error'] = 'Invalid JSON in candidates file';
            $out['tiles'] = array();
        }else{
            $candidates = $data['candidates'];
            shuffle($candidates);
            $selected = array_slice($candidates, 0, min($num, count($candidates)));

            $out['tiles'] = array();
            foreach($selected as $candidate){
                $out['tiles'][] = buildTile($candidate);
            }
        }
    }
}

else if ($action == 'log_action'){
    $out['status'] = 'OK';
    $out['message'] = 'Action logged';
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