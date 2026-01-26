<?php
$items = ['Q17393843','Q151011','Q152446','Q154672','Q155100','Q155100','Q19611648','Q19624711','Q19624712','Q3514986','Q30278766','Q2378306','Q19283932','Q22712989','Q38080891'];
$candidates = [];

foreach ($items as $item_q){
    echo "Processing $item_q...\n";

    ini_set( 'user_agent', 'hari\'s shortcut' );
    $url = "http://www.wikidata.org/w/api.php?action=wbgetclaims&entity=$item_q&property=P65&format=json";
    $json = file_get_contents($url);
    $data = json_decode($json, true);

    // get item info
    $info_url = "http://www.wikidata.org/w/api.php?action=wbgetentities&ids=$item_q&props=labels|descriptions&languages=en&format=json";
    $info_json = file_get_contents($info_url);
    $info_data = json_decode($info_json, true);

    $item_label = $info_data['entities'][$item_q]['labels']['en']['value'] ?? $item_q;
    $item_desc = $info_data['entities'][$item_q]['descriptions']['en']['value'] ?? ''; 
    
    // process the sites
    $sites = [];
    $sites_qs = [];
    foreach($data['claims']['P65'] as $claim){
        $site_q = $claim['mainsnak']['datavalue']['value']['id'];
        $sites_qs[] = $site_q;

        $sites[] = [
            'site_q' => $site_q,
            'site_label' => '',
            'site_numberic_id' => $claim['mainsnak']['datavalue']['value']['numeric-id'],
            'statement_guid' => $claim['id'],
            'has_reference' => !empty($claim['references']),
            'current_rank' => $claim['rank']
        ];
    }
    // get site labels
    $site_ids = implode('|', $sites_qs);
    $sites_url = "http://www.wikidata.org/w/api.php?action=wbgetentities&ids=$site_ids&props=labels&languages=en&format=json";
    $sites_json = file_get_contents($sites_url);
    $sites_data = json_decode($sites_json, true);

    foreach($sites as &$site){
        $q = $site['site_q'];
        $site['site_label'] = $sites_data['entities'][$q]['labels']['en']['value'] ?? $q;
    }

    $candidates[] = [
        'item_q' => $item_q,
        'item_label' => $item_label,
        'item_description' => $item_desc,
        'sites' => $sites
    ];
    sleep(1);
}
$output = [
    'metadata' => [
        'description' => 'P65 single-value constraint violations',
        'created' => date('Y-m-d'),
        'count' => count($candidates)
    ],
    'candidates' => $candidates
];

file_put_contents('/home/hkx05/Pick-the-Discovery-Site/fix-discovery-site/data/candidates.json', json_encode($output, JSON_PRETTY_PRINT));
echo "Done! Created data/candidates.json with " . count($candidates) . " candidates\n";

?>