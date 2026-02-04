# The Distributed Game - Report

## Summary

The Distributed Game created by Magnus Manske is a platform that transforms boring data quality work into a fun experience. It provides a framework for playing multiple independent games which addresses various constraint violations in Wikidata. The best part about this is that anyone can make and host their own games by implementing a simple API specification. 

### The Problem

Just like Wikipedia, Wikidata is also a huge collection of millions of items. While this scale is incredibly valuable, it also creates a lot of data quality challenges like constraint violations.

- Constraint Violations: Wikidata items have properties which have some constraints/rules which are applied to ensure data integrity, consistency and accuracy.

- Manually fixing thousands of violations is tedious and boring. So, gamifying this is really helpful and engaging for the users.

### Game Framework

1. index.html + main.js
   - Provides the game UI (tiles, buttons etc)
   - OAuth authentication using Widar.
   - Executes Wikidata API calls

2. api.php
   - Generate game content
   - Builds the tile structure
   - Use Wikidata API to make edits 

3. games.json
   - Json file with the list of active games
   - Contains each game's metadata
   - loaded by main.js to display the available games

- The framework requests the game content using HTTP, display it, use Wikidata API and track statistics.

### Games on The Distributed Game

All games are independent of each other and have their own HTTP API. They can be hosted anywhere.

### Flow of data:

1. Player loads the distributed game
2. Chooses a game and the game content is fetched from the game API
3. Player gets the content and clicks a button
4. Framework sends the API action to wikidata using the OAuth credentials
5. Game updates its database to mark the item as processed.

## Actions by api.php

### `action=desc`

Request: `GET https://unique-discovery-site.toolforge.org/api.php?action=desc&callback=hellooo`

Response: 
```json
    hellooo({
    "label": {
        "en": "Pick the Discovery site"
    },
    "description": {
        "en": "Astronomical objects should have only ONE discovery site. Help choose the correct one!"
    },
    "icon": "https://upload.wikimedia.org/wikipedia/commons/0/00/Crab_Nebula.jpg",
    "instructions": {
        "en": "Each item shows an astronomical object with multiple discovery sites listed. This violates the single-value constraint for P65. Review the options and click the button for the correct discovery site. Observatories carry more weightage. Click 'Skip' if you are unsure."
    }
})
```

- Here, we are making use of JSONP as we have cooperating servers (my tool and the distributed game tool) so using it, we can bypass the same origin policy.
- The response is wrapped in the callback function name.
- The `label` and `description` must be objects with language keys or game gets status as BAD_JSON and won't appear in the list. Initially when I was building the game, I didn't use it and got that response while testing.

### `action=tiles`

Request: `GET https://unique-discovery-site.toolforge.org/api.php?action=tiles&num=1&callback=blah`

Response: 
```json
    blah({
    "tiles": [
        {
            "id": "26",
            "sections": [
                {
                    "type": "item",
                    "q": "Q19625621"
                },
                {
                    "type": "text",
                    "title": "Single-Value Constraint Violation",
                    "text": "This astronomical object has 2 discovery sites (P65), but should only have ONE.\n\nChoose the correct site. Others will be deprecated."
                },
                {
                    "type": "text",
                    "title": "Current P65 values:",
                    "text": "* Bergisch Gladbach (Q3117) (has reference)\n* Bergisch Gladbach Observatory (Q4329953) (has reference)\n"
                }
            ],
            "controls": [
                {
                    "type": "buttons",
                    "entries": [
                        {
                            "type": "green",
                            "decision": "keep_Q3117",
                            "label": "Bergisch Gladbach",
                            "api_action": {
                                "action": "wbeditentity",
                                "id": "Q19625621",
                                "data": "{\"claims\":{\"P65\":[{\"id\":\"Q19625621$748ad17f-b2f4-4128-b826-f67dddcbafa2\",\"type\":\"statement\",\"rank\":\"deprecated\",\"mainsnak\":{\"snaktype\":\"value\",\"property\":\"P65\",\"datavalue\":{\"type\":\"wikibase-entityid\",\"value\":{\"entity-type\":\"item\",\"numeric-id\":4329953}}}}]}}",
                                "summary": "Deprecated duplicate discovery sites via [[Wikidata:Wikidata Game|Wikidata Game]]"
                            }
                        },
                        {
                            "type": "green",
                            "decision": "keep_Q4329953",
                            "label": "Bergisch Gladbach Observatory",
                            "api_action": {
                                "action": "wbeditentity",
                                "id": "Q19625621",
                                "data": "{\"claims\":{\"P65\":[{\"id\":\"Q19625621$D28447B5-61A5-4FF9-8DAB-46F24A27FCC4\",\"type\":\"statement\",\"rank\":\"deprecated\",\"mainsnak\":{\"snaktype\":\"value\",\"property\":\"P65\",\"datavalue\":{\"type\":\"wikibase-entityid\",\"value\":{\"entity-type\":\"item\",\"numeric-id\":3117}}}}]}}",
                                "summary": "Deprecated duplicate discovery sites via [[Wikidata:Wikidata Game|Wikidata Game]]"
                            }
                        },
                        {
                            "type": "white",
                            "decision": "skip",
                            "label": "Skip"
                        }
                    ]
                }
            ]
        }
    ],
    "remaining": 54
})
```

### `action=log_action`

Request: `GET https://unique-discovery-site.toolforge.org/api.php?action=log_action&tile=10&decision=keep_Q1234567&user=hari&usercallback=blah`

- Notifies the game that the player has made a decision
- Update the database
- Track the statistics

```php
'api_action' => [
    'action' => 'wbeditentity',
    'id' => $candidate['item_q'],
    'data' => json_encode([
        'claims' => [
            'P65' => [
                [
                    'id' => 'Q1256677$45773....',
                    'rank' => 'deprecated'
                ]
            ]
        ]
    ]),
    'summary' => 'Deprecated duplicate discovery sites via Wikidata Game'
]
```

- The candidate is marked as done in the database and the scores are added in the scores database.

## Building "Fix the Discovery Site"

To understand the platform, building a game on my own was the best way 

https://www.wikidata.org/wiki/Wikidata:Database_reports/Constraint_violations

This is the site that helped me the most as it contains all the resources to find the exact places where the violations are happening.

### Steps I followed

1. Find the violations which are easy to build to build a game on which can provide the user with options to choose from (like, single-value constraint)
2. Find an appropriate property which has a good number of violations (I chose P65)
3. Check the page constraint violations page of the property to find the exact items causing the violations (https://www.wikidata.org/wiki/Wikidata:Database_reports/Constraint_violations/P65)
4. Data collection: Use SPARQL query to fetch the get the details

```sparql
SELECT ?item ?itemLabel (COUNT(?site) AS ?siteCount) 

WHERE {
  ?item wdt:P65 ?site .
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en" }
}

GROUP BY ?item ?itemLabel
HAVING (COUNT(?site) > 1)
ORDER BY DESC(?siteCount)
LIMIT 200
```

5. Then I used the Wikidata API to fetch detailed information for each violation including statement GUIDs for deprecation, whether each statement has references, site labels and QIDs

Before making the production version, I used to store that data in a static json file to just see how I can use them in api.php

In the production, I have a database with tables which includes `candidates`, `users`, `scores`.

### Problems I faced

1. Took a while to figure out how to deprecate a statement using GUID
2. At first I was passing the claims as nested array and was getting cryptic error and then I came to know that it must be a JSON string and that I should use json_encode while passing it. 
3. Initially I used `wbsetclaim` to deprecate the statements but when there are more than 2 discovery sites, it wouldn't work. Then I used `wbeditentity` as it can update multiple statements at once.