<?php

namespace MatchBot\Client;

use PHPUnit\Framework\TestCase;

class LiveFindThatPostcodeTest extends TestCase
{
    /**
     * @psalm-suppress MixedArgument
     */
    public function testItParsesDataFromFTPC(): void
    {
        $retrievedData = \Safe\json_decode(json: self::FIND_THAT_POSTCODE_SAMPLE_RESPONSE, associative: true, flags: \JSON_THROW_ON_ERROR);

        $processed = LiveFindThatPostcode::parseFindThatPostcodeResponse($retrievedData);

        // this is currently returning many more than we want - and the production code in the other PR that assumes there
        // will be less than ten will fail with this. May be something we can filter by in the FTPC data to only give us
        // the sort of regions we'd be interested in, and hopefully somethign we can sort on to make sure they're ordered
        // from most to least specific.

        self::assertEquals([
                [
                    'name' => 'St James\'s',
                    'code' => 'E05013806',
                ],
                [
                    'name' => 'Westminster',
                    'code' => 'E09000033',
                ],
                [
                    'name' => 'London',
                    'code' => 'E12000007',
                ],
                [
                    'name' => 'England',
                    'code' => 'E92000001',
                ]], $processed);
    }

    /** @var string
     *
     * Jason Data retrieved from https://findthatpostcode.uk/postcodes/SW1A%201AA.json, uneditred other than to format
     * automatically.
     */
    public const string FIND_THAT_POSTCODE_SAMPLE_RESPONSE = <<<'JSON'
{
  "data": {
    "attributes": {
      "bua11": "E34004707",
      "bua11_name": "Greater London BUA",
      "bua22": "E63004916",
      "bua22_name": "City of Westminster",
      "bua24": "E63012036",
      "bua24_name": "City of Westminster",
      "buasd11": "E35000546",
      "buasd11_name": "City of Westminster BUASD",
      "calncv": "E56000021",
      "calncv_name": "North West and South West London",
      "ccg": "E38000256",
      "ccg_name": "NHS North West London ICB - W2U3Z",
      "ced": null,
      "ctry": "E92000001",
      "ctry_name": "England",
      "cty": "E13000001",
      "cty_name": "Inner London",
      "dointr": "1980-01-01",
      "doterm": null,
      "eer": "E15000007",
      "eer_name": "London",
      "hash": "bb4a3f30a76e5b96a67a71dfe54012ad",
      "hlthau": "E18000007",
      "hlthau_name": "London",
      "icb": "E54000027",
      "icb_name": "NHS North West London Integrated Care Board",
      "imd": 24862,
      "itl": "E09000033",
      "itl_name": "Westminster",
      "lad": "E09000033",
      "lad_name": "Westminster",
      "lat": 51.50101,
      "laua": "E09000033",
      "laua_name": "Westminster",
      "lep1": "E37000051",
      "lep1_name": "The London Economic Action Partnership",
      "lep2": null,
      "location": {
        "lat": 51.50101,
        "lon": -0.141563
      },
      "long": -0.141563,
      "lsoa11": "E01004736",
      "lsoa11_name": "Westminster 018C",
      "lsoa21": "E01004736",
      "lsoa21_name": "Westminster 018C",
      "msoa11": "E02000977",
      "msoa11_name": "Strand, St James & Mayfair",
      "msoa21": "E02000977",
      "msoa21_name": "Strand, St James & Mayfair",
      "nhser": "E40000003",
      "nhser_name": "London",
      "npark": "E65000001",
      "npark_name": "England Non-National Park Area",
      "oa11": "E00023938",
      "oa11_name": "",
      "oa21": "E00023938",
      "oa21_name": "",
      "oac11": {
        "code": "2C3",
        "group": "Comfortable cosmopolitan",
        "subgroup": "Professional service cosmopolitans",
        "supergroup": "Cosmopolitans"
      },
      "oseast1m": 529090,
      "osgrdind": 1,
      "osnrth1m": 179645,
      "park": "E65000001",
      "park_name": "England Non-National Park Area",
      "pcd": "SW1A1AA",
      "pcd2": "SW1A 1AA",
      "pcd7": "SW1A1AA",
      "pcd8": "SW1A 1AA",
      "pcds": "SW1A 1AA",
      "pcon": "E14001172",
      "pcon_name": "Cities of London and Westminster",
      "pct": "E16000057",
      "pct_name": "Westminster",
      "pfa": "E23000001",
      "pfa_name": "Metropolitan Police",
      "rgn": "E12000007",
      "rgn_name": "London",
      "ru11ind": {
        "code": "A1",
        "description": "Urban major conurbation"
      },
      "ruc21": {
        "code": "UN1",
        "description": "Urban: Nearer to a major town or city"
      },
      "sicbl": "E38000256",
      "sicbl_name": "NHS North West London ICB - W2U3Z",
      "stp": "E54000027",
      "stp_name": "NHS North West London Integrated Care Board",
      "teclec": "E24000014",
      "teclec_name": "London Central",
      "ttwa": "E30000234",
      "ttwa_name": "London",
      "usertype": 1,
      "ward": "E05013806",
      "ward_name": "St James's",
      "wd": "E05013806",
      "wd_name": "St James's",
      "wz11": "E33031119",
      "wz11_name": ""
    },
    "id": "SW1A 1AA",
    "links": {
      "html": "postcodes/SW1A+1AA.html",
      "self": "postcodes/SW1A+1AA"
    },
    "relationships": {
      "areas": {
        "data": [
          {
            "id": "E00023938",
            "type": "areas"
          },
          {
            "id": "E13000001",
            "type": "areas"
          },
          {
            "id": "E09000033",
            "type": "areas"
          },
          {
            "id": "E05013806",
            "type": "areas"
          },
          {
            "id": "E18000007",
            "type": "areas"
          },
          {
            "id": "E40000003",
            "type": "areas"
          },
          {
            "id": "E92000001",
            "type": "areas"
          },
          {
            "id": "E12000007",
            "type": "areas"
          },
          {
            "id": "E14001172",
            "type": "areas"
          },
          {
            "id": "E15000007",
            "type": "areas"
          },
          {
            "id": "E24000014",
            "type": "areas"
          },
          {
            "id": "E30000234",
            "type": "areas"
          },
          {
            "id": "E16000057",
            "type": "areas"
          },
          {
            "id": "E09000033",
            "type": "areas"
          },
          {
            "id": "E65000001",
            "type": "areas"
          },
          {
            "id": "E01004736",
            "type": "areas"
          },
          {
            "id": "E02000977",
            "type": "areas"
          },
          {
            "id": "E33031119",
            "type": "areas"
          },
          {
            "id": "E38000256",
            "type": "areas"
          },
          {
            "id": "E34004707",
            "type": "areas"
          },
          {
            "id": "E35000546",
            "type": "areas"
          },
          {
            "id": "E37000051",
            "type": "areas"
          },
          {
            "id": "E23000001",
            "type": "areas"
          },
          {
            "id": "E56000021",
            "type": "areas"
          },
          {
            "id": "E54000027",
            "type": "areas"
          },
          {
            "id": "E01004736",
            "type": "areas"
          },
          {
            "id": "E00023938",
            "type": "areas"
          },
          {
            "id": "E02000977",
            "type": "areas"
          },
          {
            "id": "E54000027",
            "type": "areas"
          },
          {
            "id": "E38000256",
            "type": "areas"
          },
          {
            "id": "E63004916",
            "type": "areas"
          },
          {
            "id": "E65000001",
            "type": "areas"
          },
          {
            "id": "E63012036",
            "type": "areas"
          },
          {
            "id": "E05013806",
            "type": "areas"
          },
          {
            "id": "E09000033",
            "type": "areas"
          }
        ],
        "links": {
          "related": "postcodes/SW1A+1AA/areas",
          "self": "postcodes/SW1A+1AA/relationships/areas"
        }
      },
      "nearest_places": {
        "data": [
          {
            "id": "IPN0077107",
            "type": "places"
          },
          {
            "id": "IPN0010626",
            "type": "places"
          },
          {
            "id": "IPN0029858",
            "type": "places"
          },
          {
            "id": "IPN0030177",
            "type": "places"
          },
          {
            "id": "IPN0074526",
            "type": "places"
          },
          {
            "id": "IPN0036727",
            "type": "places"
          },
          {
            "id": "IPN0066656",
            "type": "places"
          },
          {
            "id": "IPN0066661",
            "type": "places"
          },
          {
            "id": "IPN0077110",
            "type": "places"
          },
          {
            "id": "IPN0005349",
            "type": "places"
          }
        ],
        "links": {
          "related": "postcodes/SW1A+1AA/nearest_places",
          "self": "postcodes/SW1A+1AA/relationships/nearest_places"
        }
      }
    },
    "type": "postcodes"
  },
  "included": [
    {
      "attributes": {
        "active": true,
        "alternative_names": [],
        "areachect": 28.16,
        "areaehect": 28.16,
        "areaihect": 0.0,
        "arealhect": 28.16,
        "child_count": 0,
        "child_counts": {},
        "code": "E00023938",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E00",
        "equivalents": {
          "ons": "00BKGQ0013"
        },
        "name": "",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E01004736",
        "predecessor": [
          "00BKGQ0013"
        ],
        "sort_order": "E00023938",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E00023938",
      "links": {
        "html": "areas/E00023938.html",
        "self": "areas/E00023938"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "oa21",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E00023938/areatype",
            "self": "areas/E00023938/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E00023938/children",
            "self": "areas/E00023938/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E00023938/example_postcodes",
            "self": "areas/E00023938/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E01004736",
            "type": "areas"
          },
          "links": {
            "related": "areas/E00023938/parent",
            "self": "areas/E00023938/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E00023938/predecessor",
            "self": "areas/E00023938/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E00023938/successor",
            "self": "areas/E00023938/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Inner London"
        ],
        "areachect": 31924.93,
        "areaehect": 32786.38,
        "areaihect": 0.0,
        "arealhect": 31924.93,
        "child_count": 0,
        "child_counts": {},
        "code": "E13000001",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E13",
        "equivalents": {},
        "name": "Inner London",
        "name_welsh": null,
        "owner": "DLUHC",
        "parent": null,
        "predecessor": [],
        "sort_order": "E13000001",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E13000001",
      "links": {
        "html": "areas/E13000001.html",
        "self": "areas/E13000001"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "iol",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E13000001/areatype",
            "self": "areas/E13000001/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E13000001/children",
            "self": "areas/E13000001/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E13000001/example_postcodes",
            "self": "areas/E13000001/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E13000001/parent",
            "self": "areas/E13000001/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E13000001/predecessor",
            "self": "areas/E13000001/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E13000001/successor",
            "self": "areas/E13000001/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Westminster"
        ],
        "areachect": 2147.72,
        "areaehect": 2203.01,
        "areaihect": 0.0,
        "arealhect": 2147.72,
        "child_count": 64,
        "child_counts": {
          "msoa21": 24,
          "ncp": 1,
          "par": 1,
          "ward": 38
        },
        "code": "E09000033",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E09",
        "equivalents": {
          "mhclg": "X5990",
          "nhs": "713",
          "ons": "00BK"
        },
        "name": "Westminster",
        "name_welsh": null,
        "owner": "DLUHC",
        "parent": "E12000007",
        "predecessor": [
          "00BK"
        ],
        "sort_order": "E09000033",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E09000033",
      "links": {
        "html": "areas/E09000033.html",
        "self": "areas/E09000033"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "laua",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E09000033/areatype",
            "self": "areas/E09000033/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E09000033/children",
            "self": "areas/E09000033/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E09000033/example_postcodes",
            "self": "areas/E09000033/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E12000007",
            "type": "areas"
          },
          "links": {
            "related": "areas/E09000033/parent",
            "self": "areas/E09000033/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E09000033/predecessor",
            "self": "areas/E09000033/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E09000033/successor",
            "self": "areas/E09000033/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "St James's"
        ],
        "areachect": 315.14,
        "areaehect": 343.32,
        "areaihect": 0.0,
        "arealhect": 315.14,
        "child_count": 0,
        "child_counts": {},
        "code": "E05013806",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 05 May 2022 00:00:00 GMT",
        "entity": "E05",
        "equivalents": {},
        "name": "St James's",
        "name_welsh": null,
        "owner": "DLUHC",
        "parent": "E09000033",
        "predecessor": [
          "E05000644"
        ],
        "sort_order": "E05013806",
        "statutory_instrument_id": "1224/2020",
        "statutory_instrument_title": "The City of Westminster (Electoral Changes) Order 2020",
        "successor": []
      },
      "id": "E05013806",
      "links": {
        "html": "areas/E05013806.html",
        "self": "areas/E05013806"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "ward",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E05013806/areatype",
            "self": "areas/E05013806/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E05013806/children",
            "self": "areas/E05013806/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E05013806/example_postcodes",
            "self": "areas/E05013806/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E09000033",
            "type": "areas"
          },
          "links": {
            "related": "areas/E05013806/parent",
            "self": "areas/E05013806/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E05000644",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E05013806/predecessor",
            "self": "areas/E05013806/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E05013806/successor",
            "self": "areas/E05013806/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "London"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E18000007",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Sun, 31 Mar 2013 00:00:00 GMT",
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E18",
        "equivalents": {
          "ons": "Q36"
        },
        "name": "London",
        "name_welsh": null,
        "owner": "ODS",
        "parent": "E19000003",
        "predecessor": [
          "Q36"
        ],
        "sort_order": "E18000007",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": [
          ""
        ]
      },
      "id": "E18000007",
      "links": {
        "html": "areas/E18000007.html",
        "self": "areas/E18000007"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "sha",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E18000007/areatype",
            "self": "areas/E18000007/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E18000007/children",
            "self": "areas/E18000007/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E18000007/example_postcodes",
            "self": "areas/E18000007/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E19000003",
            "type": "areas"
          },
          "links": {
            "related": "areas/E18000007/parent",
            "self": "areas/E18000007/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E18000007/predecessor",
            "self": "areas/E18000007/relationships/predecessor"
          }
        },
        "successor": {
          "data": [],
          "links": {
            "related": "areas/E18000007/successor",
            "self": "areas/E18000007/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "London"
        ],
        "areachect": 157342.3,
        "areaehect": 159472.03,
        "areaihect": 136.93,
        "arealhect": 157205.37,
        "child_count": 6,
        "child_counts": {
          "icb": 5,
          "nhsrlo": 1
        },
        "code": "E40000003",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Mon, 01 Apr 2013 00:00:00 GMT",
        "entity": "E40",
        "equivalents": {
          "nhs": "Y56"
        },
        "name": "London",
        "name_welsh": null,
        "owner": "ODS",
        "parent": null,
        "predecessor": [],
        "sort_order": "E40000003",
        "statutory_instrument_id": "2996/2012",
        "statutory_instrument_title": "The National Health Service Commissioning Board and Clinical Commissioning Groups (Responsibilities and Standing Rules) Regulations 2012",
        "successor": []
      },
      "id": "E40000003",
      "links": {
        "html": "areas/E40000003.html",
        "self": "areas/E40000003"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "nhser",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E40000003/areatype",
            "self": "areas/E40000003/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E40000003/children",
            "self": "areas/E40000003/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E40000003/example_postcodes",
            "self": "areas/E40000003/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E40000003/parent",
            "self": "areas/E40000003/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E40000003/predecessor",
            "self": "areas/E40000003/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E40000003/successor",
            "self": "areas/E40000003/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "England",
          "Lloegr"
        ],
        "areachect": 13046317.35,
        "areaehect": 13292909.69,
        "areaihect": 15188.35,
        "arealhect": 13031129.0,
        "child_count": 10000,
        "child_counts": {
          "bua11": 5360,
          "cmcty": 35,
          "cmlad": 55,
          "eer": 9,
          "regd": 155,
          "rgn": 9,
          "ttwa": 149
        },
        "code": "E92000001",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E92",
        "equivalents": {
          "mhclg": "W0015",
          "nhs": "E",
          "ons": "921"
        },
        "name": "England",
        "name_welsh": "Lloegr",
        "owner": "ONS",
        "parent": null,
        "predecessor": [
          "921"
        ],
        "sort_order": "E92000001",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E92000001",
      "links": {
        "html": "areas/E92000001.html",
        "self": "areas/E92000001"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "ctry",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E92000001/areatype",
            "self": "areas/E92000001/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E92000001/children",
            "self": "areas/E92000001/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E92000001/example_postcodes",
            "self": "areas/E92000001/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E92000001/parent",
            "self": "areas/E92000001/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E92000001/predecessor",
            "self": "areas/E92000001/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E92000001/successor",
            "self": "areas/E92000001/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "London"
        ],
        "areachect": 157339.98,
        "areaehect": 159469.69,
        "areaihect": 136.92,
        "arealhect": 157203.06,
        "child_count": 83,
        "child_counts": {
          "gla": 1,
          "lac": 14,
          "laua": 33,
          "lpa": 35
        },
        "code": "E12000007",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E12",
        "equivalents": {
          "mhclg": "Z0089",
          "nhs": "09",
          "ons": "H"
        },
        "name": "London",
        "name_welsh": null,
        "owner": "DLUHC",
        "parent": "E92000001",
        "predecessor": [
          "H"
        ],
        "sort_order": "E12000007",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E12000007",
      "links": {
        "html": "areas/E12000007.html",
        "self": "areas/E12000007"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "rgn",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E12000007/areatype",
            "self": "areas/E12000007/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E12000007/children",
            "self": "areas/E12000007/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E12000007/example_postcodes",
            "self": "areas/E12000007/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E92000001",
            "type": "areas"
          },
          "links": {
            "related": "areas/E12000007/parent",
            "self": "areas/E12000007/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E12000007/predecessor",
            "self": "areas/E12000007/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E12000007/successor",
            "self": "areas/E12000007/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Cities of London and Westminster"
        ],
        "areachect": 1861.13,
        "areaehect": 1942.37,
        "areaihect": 0.0,
        "arealhect": 1861.13,
        "child_count": 0,
        "child_counts": {},
        "code": "E14001172",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 04 Jul 2024 00:00:00 GMT",
        "entity": "E14",
        "equivalents": {},
        "name": "Cities of London and Westminster",
        "name_welsh": null,
        "owner": "LGBC",
        "parent": null,
        "predecessor": [
          "E14000639",
          "E14001036"
        ],
        "sort_order": "E14001172",
        "statutory_instrument_id": "1230/2023",
        "statutory_instrument_title": "The Parliamentary Constituencies Order 2023",
        "successor": []
      },
      "id": "E14001172",
      "links": {
        "html": "areas/E14001172.html",
        "self": "areas/E14001172"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "pcon",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E14001172/areatype",
            "self": "areas/E14001172/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E14001172/children",
            "self": "areas/E14001172/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E14001172/example_postcodes",
            "self": "areas/E14001172/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E14001172/parent",
            "self": "areas/E14001172/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E14000639",
              "type": "areas"
            },
            {
              "id": "E14001036",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E14001172/predecessor",
            "self": "areas/E14001172/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E14001172/successor",
            "self": "areas/E14001172/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "London"
        ],
        "areachect": 157339.99,
        "areaehect": 159469.67,
        "areaihect": 136.93,
        "arealhect": 157203.06,
        "child_count": 147,
        "child_counts": {
          "pcon": 147
        },
        "code": "E15000007",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Thu, 31 Dec 2020 00:00:00 GMT",
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E15",
        "equivalents": {
          "ons": "07"
        },
        "name": "London",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E92000001",
        "predecessor": [
          "07"
        ],
        "sort_order": "E15000007",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": [
          ""
        ]
      },
      "id": "E15000007",
      "links": {
        "html": "areas/E15000007.html",
        "self": "areas/E15000007"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "eer",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E15000007/areatype",
            "self": "areas/E15000007/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E15000007/children",
            "self": "areas/E15000007/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E15000007/example_postcodes",
            "self": "areas/E15000007/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E92000001",
            "type": "areas"
          },
          "links": {
            "related": "areas/E15000007/parent",
            "self": "areas/E15000007/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E15000007/predecessor",
            "self": "areas/E15000007/relationships/predecessor"
          }
        },
        "successor": {
          "data": [],
          "links": {
            "related": "areas/E15000007/successor",
            "self": "areas/E15000007/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "London Central"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E24000014",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Wed, 31 Mar 2010 00:00:00 GMT",
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E24",
        "equivalents": {
          "ons": "GL140"
        },
        "name": "London Central",
        "name_welsh": null,
        "owner": "ONS",
        "parent": null,
        "predecessor": [
          "GL140"
        ],
        "sort_order": "E24000014",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E24000014",
      "links": {
        "html": "areas/E24000014.html",
        "self": "areas/E24000014"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "llsc",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E24000014/areatype",
            "self": "areas/E24000014/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E24000014/children",
            "self": "areas/E24000014/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E24000014/example_postcodes",
            "self": "areas/E24000014/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E24000014/parent",
            "self": "areas/E24000014/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E24000014/predecessor",
            "self": "areas/E24000014/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E24000014/successor",
            "self": "areas/E24000014/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "London"
        ],
        "areachect": 217743.65,
        "areaehect": 221132.44,
        "areaihect": 136.93,
        "arealhect": 217606.72,
        "child_count": 0,
        "child_counts": {},
        "code": "E30000234",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Fri, 31 Jul 2015 00:00:00 GMT",
        "entity": "E30",
        "equivalents": {},
        "name": "London",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E92000001",
        "predecessor": [],
        "sort_order": "E30000234",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E30000234",
      "links": {
        "html": "areas/E30000234.html",
        "self": "areas/E30000234"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "ttwa",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E30000234/areatype",
            "self": "areas/E30000234/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E30000234/children",
            "self": "areas/E30000234/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E30000234/example_postcodes",
            "self": "areas/E30000234/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E92000001",
            "type": "areas"
          },
          "links": {
            "related": "areas/E30000234/parent",
            "self": "areas/E30000234/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E30000234/predecessor",
            "self": "areas/E30000234/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E30000234/successor",
            "self": "areas/E30000234/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "Westminster"
        ],
        "areachect": 2148.7,
        "areaehect": 2203.01,
        "areaihect": 0.0,
        "arealhect": 2148.7,
        "child_count": 0,
        "child_counts": {},
        "code": "E16000057",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Sun, 31 Mar 2013 00:00:00 GMT",
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E16",
        "equivalents": {
          "ons": "5LC"
        },
        "name": "Westminster",
        "name_welsh": null,
        "owner": "ODS",
        "parent": null,
        "predecessor": [
          "5LC"
        ],
        "sort_order": "E16000057",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": [
          ""
        ]
      },
      "id": "E16000057",
      "links": {
        "html": "areas/E16000057.html",
        "self": "areas/E16000057"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "pct",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E16000057/areatype",
            "self": "areas/E16000057/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E16000057/children",
            "self": "areas/E16000057/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E16000057/example_postcodes",
            "self": "areas/E16000057/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E16000057/parent",
            "self": "areas/E16000057/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E16000057/predecessor",
            "self": "areas/E16000057/relationships/predecessor"
          }
        },
        "successor": {
          "data": [],
          "links": {
            "related": "areas/E16000057/successor",
            "self": "areas/E16000057/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Westminster"
        ],
        "areachect": 2147.72,
        "areaehect": 2203.01,
        "areaihect": 0.0,
        "arealhect": 2147.72,
        "child_count": 64,
        "child_counts": {
          "msoa21": 24,
          "ncp": 1,
          "par": 1,
          "ward": 38
        },
        "code": "E09000033",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E09",
        "equivalents": {
          "mhclg": "X5990",
          "nhs": "713",
          "ons": "00BK"
        },
        "name": "Westminster",
        "name_welsh": null,
        "owner": "DLUHC",
        "parent": "E12000007",
        "predecessor": [
          "00BK"
        ],
        "sort_order": "E09000033",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E09000033",
      "links": {
        "html": "areas/E09000033.html",
        "self": "areas/E09000033"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "laua",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E09000033/areatype",
            "self": "areas/E09000033/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E09000033/children",
            "self": "areas/E09000033/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E09000033/example_postcodes",
            "self": "areas/E09000033/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E12000007",
            "type": "areas"
          },
          "links": {
            "related": "areas/E09000033/parent",
            "self": "areas/E09000033/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E09000033/predecessor",
            "self": "areas/E09000033/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E09000033/successor",
            "self": "areas/E09000033/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "England Non-National Park Area"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E65000001",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Mon, 27 Feb 2023 00:00:00 GMT",
        "entity": "E65",
        "equivalents": {},
        "name": "England Non-National Park Area",
        "name_welsh": null,
        "owner": "ONS",
        "parent": null,
        "predecessor": [],
        "sort_order": "E65000001",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E65000001",
      "links": {
        "html": "areas/E65000001.html",
        "self": "areas/E65000001"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "E65",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E65000001/areatype",
            "self": "areas/E65000001/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E65000001/children",
            "self": "areas/E65000001/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E65000001/example_postcodes",
            "self": "areas/E65000001/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E65000001/parent",
            "self": "areas/E65000001/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E65000001/predecessor",
            "self": "areas/E65000001/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E65000001/successor",
            "self": "areas/E65000001/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Westminster 018C"
        ],
        "areachect": 119.04,
        "areaehect": 119.04,
        "areaihect": 0.0,
        "arealhect": 119.04,
        "child_count": 5,
        "child_counts": {
          "oa21": 5
        },
        "code": "E01004736",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Sun, 01 Feb 2004 00:00:00 GMT",
        "entity": "E01",
        "equivalents": {},
        "name": "Westminster 018C",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E02000977",
        "predecessor": [],
        "sort_order": "E01004736",
        "stats": {
          "idaci2015": {
            "idaci_decile": 6,
            "idaci_rank": 19343,
            "idaci_score": 0.117
          },
          "idaci2019": {
            "idaci_decile": 10,
            "idaci_rank": 32297,
            "idaci_score": 0.015
          },
          "idaci2025": {
            "idaci_decile": 8,
            "idaci_rank": 26574,
            "idaci_score": 0.153
          },
          "idaopi2015": {
            "idaopi_decile": 10,
            "idaopi_rank": 32604,
            "idaopi_score": 0.026
          },
          "idaopi2019": {
            "idaopi_decile": 10,
            "idaopi_rank": 32722,
            "idaopi_score": 0.018
          },
          "idaopi2025": {
            "idaopi_decile": 10,
            "idaopi_rank": 32079,
            "idaopi_score": 0.036
          },
          "imd2015": {
            "imd_crime_decile": 3,
            "imd_crime_rank": 6668,
            "imd_crime_score": 0.678,
            "imd_decile": 5,
            "imd_education_adults_decile": 10,
            "imd_education_adults_rank": 32492,
            "imd_education_adults_score": 0.081,
            "imd_education_children_decile": 4,
            "imd_education_children_rank": 11829,
            "imd_education_children_score": 0.281,
            "imd_education_decile": 7,
            "imd_education_rank": 20379,
            "imd_education_score": 11.606,
            "imd_employment_decile": 10,
            "imd_employment_rank": 32811,
            "imd_employment_score": 0.01,
            "imd_environment_decile": 1,
            "imd_environment_indoors_decile": 1,
            "imd_environment_indoors_rank": 695,
            "imd_environment_indoors_score": 1.721,
            "imd_environment_outdoors_rank": 1,
            "imd_environment_outdoors_score": 2.694,
            "imd_environment_rank": 12,
            "imd_environment_score": 84.759,
            "imd_health_decile": 10,
            "imd_health_rank": 32557,
            "imd_health_score": -2.056,
            "imd_housing_decile": 2,
            "imd_housing_geographical_decile": 8,
            "imd_housing_geographical_rank": 24604,
            "imd_housing_geographical_score": -0.53,
            "imd_housing_rank": 6529,
            "imd_housing_score": 30.41,
            "imd_housing_wider_decile": 1,
            "imd_housing_wider_rank": 2713,
            "imd_housing_wider_score": 3.644,
            "imd_income_decile": 10,
            "imd_income_rank": 32775,
            "imd_income_score": 0.013,
            "imd_rank": 16419,
            "imd_score": 17.399
          },
          "imd2019": {
            "imd_crime_decile": 6,
            "imd_crime_rank": 16515,
            "imd_crime_score": 0.01,
            "imd_decile": 8,
            "imd_education_adults_decile": 10,
            "imd_education_adults_rank": 32492,
            "imd_education_adults_score": 0.081,
            "imd_education_children_decile": 7,
            "imd_education_children_rank": 22807,
            "imd_education_children_score": -0.425,
            "imd_education_decile": 9,
            "imd_education_rank": 28230,
            "imd_education_score": 4.252,
            "imd_employment_decile": 10,
            "imd_employment_rank": 32702,
            "imd_employment_score": 0.012,
            "imd_environment_decile": 1,
            "imd_environment_indoors_decile": 3,
            "imd_environment_indoors_rank": 7273,
            "imd_environment_indoors_score": 0.654,
            "imd_environment_outdoors_rank": 1,
            "imd_environment_outdoors_score": 2.331,
            "imd_environment_rank": 1239,
            "imd_environment_score": 54.538,
            "imd_health_decile": 10,
            "imd_health_rank": 32709,
            "imd_health_score": -2.151,
            "imd_housing_decile": 6,
            "imd_housing_geographical_decile": 9,
            "imd_housing_geographical_rank": 28829,
            "imd_housing_geographical_score": -0.887,
            "imd_housing_rank": 17078,
            "imd_housing_score": 19.643,
            "imd_housing_wider_decile": 2,
            "imd_housing_wider_rank": 6428,
            "imd_housing_wider_score": 2.169,
            "imd_income_decile": 10,
            "imd_income_rank": 32833,
            "imd_income_score": 0.006,
            "imd_rank": 24862,
            "imd_score": 9.719
          },
          "imd2025": {
            "imd_crime_decile": 5,
            "imd_crime_rank": 15082,
            "imd_crime_score": 0.114,
            "imd_decile": 8,
            "imd_education_adults_decile": 10,
            "imd_education_adults_rank": 31433,
            "imd_education_adults_score": 0.113,
            "imd_education_children_rank": 29595,
            "imd_education_children_score": -1.004,
            "imd_education_decile": 10,
            "imd_education_rank": 31369,
            "imd_education_score": 2.301,
            "imd_employment_decile": 10,
            "imd_employment_rank": 33354,
            "imd_employment_score": 0.028,
            "imd_environment_decile": 1,
            "imd_environment_indoors_decile": 2,
            "imd_environment_indoors_rank": 4392,
            "imd_environment_indoors_score": 0.767,
            "imd_environment_outdoors_rank": 1,
            "imd_environment_outdoors_score": 2.302,
            "imd_environment_rank": 851,
            "imd_environment_score": 60.172,
            "imd_health_decile": 9,
            "imd_health_rank": 27279,
            "imd_health_score": -0.78,
            "imd_housing_decile": 8,
            "imd_housing_geographical_decile": 10,
            "imd_housing_geographical_rank": 33528,
            "imd_housing_geographical_score": 4.817,
            "imd_housing_rank": 24926,
            "imd_housing_score": 14.475,
            "imd_housing_wider_decile": 3,
            "imd_housing_wider_rank": 9337,
            "imd_housing_wider_score": 1.671,
            "imd_income_decile": 10,
            "imd_income_rank": 33418,
            "imd_income_score": 0.028,
            "imd_rank": 24593,
            "imd_score": 10.324
          },
          "population2012": {
            "population_0_15": 68,
            "population_16_59": 1594,
            "population_60_plus": 296,
            "population_total": 1958,
            "population_workingage": 1590
          },
          "population2015": {
            "population_0_15": 128,
            "population_16_59": 1176,
            "population_60_plus": 351,
            "population_total": 1655,
            "population_workingage": 1248
          },
          "population2022": {
            "population_0_15": 77,
            "population_60_plus": 222,
            "population_total": 1656,
            "population_workingage": 1409
          }
        },
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E01004736",
      "links": {
        "html": "areas/E01004736.html",
        "self": "areas/E01004736"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "lsoa21",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E01004736/areatype",
            "self": "areas/E01004736/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E01004736/children",
            "self": "areas/E01004736/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E01004736/example_postcodes",
            "self": "areas/E01004736/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E02000977",
            "type": "areas"
          },
          "links": {
            "related": "areas/E01004736/parent",
            "self": "areas/E01004736/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E01004736/predecessor",
            "self": "areas/E01004736/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E01004736/successor",
            "self": "areas/E01004736/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Strand, St James & Mayfair"
        ],
        "areachect": 251.46,
        "areaehect": 259.47,
        "areaihect": 0.0,
        "arealhect": 251.46,
        "child_count": 283,
        "child_counts": {
          "lsoa21": 4,
          "wz11": 279
        },
        "code": "E02000977",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Sun, 01 Aug 2004 00:00:00 GMT",
        "entity": "E02",
        "equivalents": {},
        "name": "Strand, St James & Mayfair",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E09000033",
        "predecessor": [],
        "sort_order": "E02000977",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E02000977",
      "links": {
        "html": "areas/E02000977.html",
        "self": "areas/E02000977"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "msoa21",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E02000977/areatype",
            "self": "areas/E02000977/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E02000977/children",
            "self": "areas/E02000977/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E02000977/example_postcodes",
            "self": "areas/E02000977/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E09000033",
            "type": "areas"
          },
          "links": {
            "related": "areas/E02000977/parent",
            "self": "areas/E02000977/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E02000977/predecessor",
            "self": "areas/E02000977/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E02000977/successor",
            "self": "areas/E02000977/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [],
        "areachect": 25.68,
        "areaehect": 25.68,
        "areaihect": 0.0,
        "arealhect": 25.68,
        "child_count": 0,
        "child_counts": {},
        "code": "E33031119",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Sat, 31 Dec 2011 00:00:00 GMT",
        "entity": "E33",
        "equivalents": {},
        "name": "",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E02000977",
        "predecessor": [],
        "sort_order": "E33031119",
        "statutory_instrument_id": "2011/1001",
        "statutory_instrument_title": "2011 Census Maintenance",
        "successor": []
      },
      "id": "E33031119",
      "links": {
        "html": "areas/E33031119.html",
        "self": "areas/E33031119"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "wz11",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E33031119/areatype",
            "self": "areas/E33031119/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E33031119/children",
            "self": "areas/E33031119/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E33031119/example_postcodes",
            "self": "areas/E33031119/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E02000977",
            "type": "areas"
          },
          "links": {
            "related": "areas/E33031119/parent",
            "self": "areas/E33031119/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E33031119/predecessor",
            "self": "areas/E33031119/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E33031119/successor",
            "self": "areas/E33031119/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "NHS North West London CCG",
          "NHS North West London ICB - W2U3Z"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E38000256",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Apr 2021 00:00:00 GMT",
        "entity": "E38",
        "equivalents": {
          "nhs": "W2U3Z"
        },
        "name": "NHS North West London ICB - W2U3Z",
        "name_welsh": null,
        "owner": "ODS",
        "parent": "E54000027",
        "predecessor": [
          "E38000020",
          "E38000031",
          "E38000048",
          "E38000070",
          "E38000074",
          "E38000082",
          "E38000084",
          "E38000202",
          "E38000256"
        ],
        "sort_order": "E38000256",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": [
          "E38000256"
        ]
      },
      "id": "E38000256",
      "links": {
        "html": "areas/E38000256.html",
        "self": "areas/E38000256"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "ccg",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E38000256/areatype",
            "self": "areas/E38000256/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E38000256/children",
            "self": "areas/E38000256/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E38000256/example_postcodes",
            "self": "areas/E38000256/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E54000027",
            "type": "areas"
          },
          "links": {
            "related": "areas/E38000256/parent",
            "self": "areas/E38000256/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E38000020",
              "type": "areas"
            },
            {
              "id": "E38000031",
              "type": "areas"
            },
            {
              "id": "E38000048",
              "type": "areas"
            },
            {
              "id": "E38000070",
              "type": "areas"
            },
            {
              "id": "E38000074",
              "type": "areas"
            },
            {
              "id": "E38000082",
              "type": "areas"
            },
            {
              "id": "E38000084",
              "type": "areas"
            },
            {
              "id": "E38000202",
              "type": "areas"
            },
            {
              "id": "E38000256",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E38000256/predecessor",
            "self": "areas/E38000256/relationships/predecessor"
          }
        },
        "successor": {
          "data": [
            {
              "id": "E38000256",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E38000256/successor",
            "self": "areas/E38000256/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "Greater London BUA"
        ],
        "areachect": null,
        "areaehect": 173785.5,
        "areaihect": 0.0,
        "arealhect": 173785.5,
        "child_count": 104,
        "child_counts": {
          "buasd11": 104
        },
        "code": "E34004707",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Wed, 30 Nov 2022 00:00:00 GMT",
        "date_start": "Sun, 27 Mar 2011 00:00:00 GMT",
        "entity": "E34",
        "equivalents": {},
        "name": "Greater London BUA",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E92000001",
        "predecessor": [],
        "sort_order": "E34004707",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": [
          ""
        ]
      },
      "id": "E34004707",
      "links": {
        "html": "areas/E34004707.html",
        "self": "areas/E34004707"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "bua11",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E34004707/areatype",
            "self": "areas/E34004707/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E34004707/children",
            "self": "areas/E34004707/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E34004707/example_postcodes",
            "self": "areas/E34004707/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E92000001",
            "type": "areas"
          },
          "links": {
            "related": "areas/E34004707/parent",
            "self": "areas/E34004707/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E34004707/predecessor",
            "self": "areas/E34004707/relationships/predecessor"
          }
        },
        "successor": {
          "data": [],
          "links": {
            "related": "areas/E34004707/successor",
            "self": "areas/E34004707/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "City of Westminster BUASD"
        ],
        "areachect": null,
        "areaehect": 2171.75,
        "areaihect": 0.0,
        "arealhect": 2171.75,
        "child_count": 0,
        "child_counts": {},
        "code": "E35000546",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Wed, 30 Nov 2022 00:00:00 GMT",
        "date_start": "Sun, 27 Mar 2011 00:00:00 GMT",
        "entity": "E35",
        "equivalents": {},
        "name": "City of Westminster BUASD",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E34004707",
        "predecessor": [],
        "sort_order": "E35000546",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": [
          ""
        ]
      },
      "id": "E35000546",
      "links": {
        "html": "areas/E35000546.html",
        "self": "areas/E35000546"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "buasd11",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E35000546/areatype",
            "self": "areas/E35000546/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E35000546/children",
            "self": "areas/E35000546/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E35000546/example_postcodes",
            "self": "areas/E35000546/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E34004707",
            "type": "areas"
          },
          "links": {
            "related": "areas/E35000546/parent",
            "self": "areas/E35000546/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E35000546/predecessor",
            "self": "areas/E35000546/relationships/predecessor"
          }
        },
        "successor": {
          "data": [],
          "links": {
            "related": "areas/E35000546/successor",
            "self": "areas/E35000546/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "London",
          "The London Economic Action Partnership"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E37000051",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Sun, 31 Mar 2024 00:00:00 GMT",
        "date_start": "Wed, 01 Apr 2020 00:00:00 GMT",
        "entity": "E37",
        "equivalents": {},
        "name": "The London Economic Action Partnership",
        "name_welsh": null,
        "owner": "BEIS",
        "parent": null,
        "predecessor": [
          "E37000004",
          "E37000023",
          "E37000051"
        ],
        "sort_order": "E37000051",
        "statutory_instrument_id": null,
        "statutory_instrument_title": "Name Change",
        "successor": [
          "E37000051",
          ""
        ]
      },
      "id": "E37000051",
      "links": {
        "html": "areas/E37000051.html",
        "self": "areas/E37000051"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "lep",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E37000051/areatype",
            "self": "areas/E37000051/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E37000051/children",
            "self": "areas/E37000051/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E37000051/example_postcodes",
            "self": "areas/E37000051/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E37000051/parent",
            "self": "areas/E37000051/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E37000004",
              "type": "areas"
            },
            {
              "id": "E37000023",
              "type": "areas"
            },
            {
              "id": "E37000051",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E37000051/predecessor",
            "self": "areas/E37000051/relationships/predecessor"
          }
        },
        "successor": {
          "data": [
            {
              "id": "E37000051",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E37000051/successor",
            "self": "areas/E37000051/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Metropolitan Police"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 32,
        "child_counts": {
          "csp": 32
        },
        "code": "E23000001",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E23",
        "equivalents": {
          "ons": "01"
        },
        "name": "Metropolitan Police",
        "name_welsh": null,
        "owner": "Home Office",
        "parent": null,
        "predecessor": [
          "01"
        ],
        "sort_order": "E23000001",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E23000001",
      "links": {
        "html": "areas/E23000001.html",
        "self": "areas/E23000001"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "pfa",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E23000001/areatype",
            "self": "areas/E23000001/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E23000001/children",
            "self": "areas/E23000001/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E23000001/example_postcodes",
            "self": "areas/E23000001/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E23000001/parent",
            "self": "areas/E23000001/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E23000001/predecessor",
            "self": "areas/E23000001/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E23000001/successor",
            "self": "areas/E23000001/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "North West and South West London"
        ],
        "areachect": 66780.36,
        "areaehect": 67231.91,
        "areaihect": 0.0,
        "arealhect": 66780.36,
        "child_count": 0,
        "child_counts": {},
        "code": "E56000021",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Mon, 01 Jul 2019 00:00:00 GMT",
        "entity": "E56",
        "equivalents": {},
        "name": "North West and South West London",
        "name_welsh": null,
        "owner": "NHS England",
        "parent": null,
        "predecessor": [
          "E57000003"
        ],
        "sort_order": "E56000021",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E56000021",
      "links": {
        "html": "areas/E56000021.html",
        "self": "areas/E56000021"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "cal",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E56000021/areatype",
            "self": "areas/E56000021/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E56000021/children",
            "self": "areas/E56000021/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E56000021/example_postcodes",
            "self": "areas/E56000021/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E56000021/parent",
            "self": "areas/E56000021/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E57000003",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E56000021/predecessor",
            "self": "areas/E56000021/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E56000021/successor",
            "self": "areas/E56000021/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "North West London",
          "North West London Health and Care Partnership",
          "NHS North West London Integrated Care Board"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 9,
        "child_counts": {
          "ccg": 9
        },
        "code": "E54000027",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Fri, 01 Apr 2016 00:00:00 GMT",
        "entity": "E54",
        "equivalents": {
          "nhs": "QRV"
        },
        "name": "NHS North West London Integrated Care Board",
        "name_welsh": null,
        "owner": "ODS",
        "parent": "E40000003",
        "predecessor": [
          "E54000027",
          "E54000027"
        ],
        "sort_order": "E54000027",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": [
          "E54000027",
          "E54000027"
        ]
      },
      "id": "E54000027",
      "links": {
        "html": "areas/E54000027.html",
        "self": "areas/E54000027"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "icb",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E54000027/areatype",
            "self": "areas/E54000027/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E54000027/children",
            "self": "areas/E54000027/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E54000027/example_postcodes",
            "self": "areas/E54000027/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E40000003",
            "type": "areas"
          },
          "links": {
            "related": "areas/E54000027/parent",
            "self": "areas/E54000027/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E54000027",
              "type": "areas"
            },
            {
              "id": "E54000027",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E54000027/predecessor",
            "self": "areas/E54000027/relationships/predecessor"
          }
        },
        "successor": {
          "data": [
            {
              "id": "E54000027",
              "type": "areas"
            },
            {
              "id": "E54000027",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E54000027/successor",
            "self": "areas/E54000027/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Westminster 018C"
        ],
        "areachect": 119.04,
        "areaehect": 119.04,
        "areaihect": 0.0,
        "arealhect": 119.04,
        "child_count": 5,
        "child_counts": {
          "oa21": 5
        },
        "code": "E01004736",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Sun, 01 Feb 2004 00:00:00 GMT",
        "entity": "E01",
        "equivalents": {},
        "name": "Westminster 018C",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E02000977",
        "predecessor": [],
        "sort_order": "E01004736",
        "stats": {
          "idaci2015": {
            "idaci_decile": 6,
            "idaci_rank": 19343,
            "idaci_score": 0.117
          },
          "idaci2019": {
            "idaci_decile": 10,
            "idaci_rank": 32297,
            "idaci_score": 0.015
          },
          "idaci2025": {
            "idaci_decile": 8,
            "idaci_rank": 26574,
            "idaci_score": 0.153
          },
          "idaopi2015": {
            "idaopi_decile": 10,
            "idaopi_rank": 32604,
            "idaopi_score": 0.026
          },
          "idaopi2019": {
            "idaopi_decile": 10,
            "idaopi_rank": 32722,
            "idaopi_score": 0.018
          },
          "idaopi2025": {
            "idaopi_decile": 10,
            "idaopi_rank": 32079,
            "idaopi_score": 0.036
          },
          "imd2015": {
            "imd_crime_decile": 3,
            "imd_crime_rank": 6668,
            "imd_crime_score": 0.678,
            "imd_decile": 5,
            "imd_education_adults_decile": 10,
            "imd_education_adults_rank": 32492,
            "imd_education_adults_score": 0.081,
            "imd_education_children_decile": 4,
            "imd_education_children_rank": 11829,
            "imd_education_children_score": 0.281,
            "imd_education_decile": 7,
            "imd_education_rank": 20379,
            "imd_education_score": 11.606,
            "imd_employment_decile": 10,
            "imd_employment_rank": 32811,
            "imd_employment_score": 0.01,
            "imd_environment_decile": 1,
            "imd_environment_indoors_decile": 1,
            "imd_environment_indoors_rank": 695,
            "imd_environment_indoors_score": 1.721,
            "imd_environment_outdoors_rank": 1,
            "imd_environment_outdoors_score": 2.694,
            "imd_environment_rank": 12,
            "imd_environment_score": 84.759,
            "imd_health_decile": 10,
            "imd_health_rank": 32557,
            "imd_health_score": -2.056,
            "imd_housing_decile": 2,
            "imd_housing_geographical_decile": 8,
            "imd_housing_geographical_rank": 24604,
            "imd_housing_geographical_score": -0.53,
            "imd_housing_rank": 6529,
            "imd_housing_score": 30.41,
            "imd_housing_wider_decile": 1,
            "imd_housing_wider_rank": 2713,
            "imd_housing_wider_score": 3.644,
            "imd_income_decile": 10,
            "imd_income_rank": 32775,
            "imd_income_score": 0.013,
            "imd_rank": 16419,
            "imd_score": 17.399
          },
          "imd2019": {
            "imd_crime_decile": 6,
            "imd_crime_rank": 16515,
            "imd_crime_score": 0.01,
            "imd_decile": 8,
            "imd_education_adults_decile": 10,
            "imd_education_adults_rank": 32492,
            "imd_education_adults_score": 0.081,
            "imd_education_children_decile": 7,
            "imd_education_children_rank": 22807,
            "imd_education_children_score": -0.425,
            "imd_education_decile": 9,
            "imd_education_rank": 28230,
            "imd_education_score": 4.252,
            "imd_employment_decile": 10,
            "imd_employment_rank": 32702,
            "imd_employment_score": 0.012,
            "imd_environment_decile": 1,
            "imd_environment_indoors_decile": 3,
            "imd_environment_indoors_rank": 7273,
            "imd_environment_indoors_score": 0.654,
            "imd_environment_outdoors_rank": 1,
            "imd_environment_outdoors_score": 2.331,
            "imd_environment_rank": 1239,
            "imd_environment_score": 54.538,
            "imd_health_decile": 10,
            "imd_health_rank": 32709,
            "imd_health_score": -2.151,
            "imd_housing_decile": 6,
            "imd_housing_geographical_decile": 9,
            "imd_housing_geographical_rank": 28829,
            "imd_housing_geographical_score": -0.887,
            "imd_housing_rank": 17078,
            "imd_housing_score": 19.643,
            "imd_housing_wider_decile": 2,
            "imd_housing_wider_rank": 6428,
            "imd_housing_wider_score": 2.169,
            "imd_income_decile": 10,
            "imd_income_rank": 32833,
            "imd_income_score": 0.006,
            "imd_rank": 24862,
            "imd_score": 9.719
          },
          "imd2025": {
            "imd_crime_decile": 5,
            "imd_crime_rank": 15082,
            "imd_crime_score": 0.114,
            "imd_decile": 8,
            "imd_education_adults_decile": 10,
            "imd_education_adults_rank": 31433,
            "imd_education_adults_score": 0.113,
            "imd_education_children_rank": 29595,
            "imd_education_children_score": -1.004,
            "imd_education_decile": 10,
            "imd_education_rank": 31369,
            "imd_education_score": 2.301,
            "imd_employment_decile": 10,
            "imd_employment_rank": 33354,
            "imd_employment_score": 0.028,
            "imd_environment_decile": 1,
            "imd_environment_indoors_decile": 2,
            "imd_environment_indoors_rank": 4392,
            "imd_environment_indoors_score": 0.767,
            "imd_environment_outdoors_rank": 1,
            "imd_environment_outdoors_score": 2.302,
            "imd_environment_rank": 851,
            "imd_environment_score": 60.172,
            "imd_health_decile": 9,
            "imd_health_rank": 27279,
            "imd_health_score": -0.78,
            "imd_housing_decile": 8,
            "imd_housing_geographical_decile": 10,
            "imd_housing_geographical_rank": 33528,
            "imd_housing_geographical_score": 4.817,
            "imd_housing_rank": 24926,
            "imd_housing_score": 14.475,
            "imd_housing_wider_decile": 3,
            "imd_housing_wider_rank": 9337,
            "imd_housing_wider_score": 1.671,
            "imd_income_decile": 10,
            "imd_income_rank": 33418,
            "imd_income_score": 0.028,
            "imd_rank": 24593,
            "imd_score": 10.324
          },
          "population2012": {
            "population_0_15": 68,
            "population_16_59": 1594,
            "population_60_plus": 296,
            "population_total": 1958,
            "population_workingage": 1590
          },
          "population2015": {
            "population_0_15": 128,
            "population_16_59": 1176,
            "population_60_plus": 351,
            "population_total": 1655,
            "population_workingage": 1248
          },
          "population2022": {
            "population_0_15": 77,
            "population_60_plus": 222,
            "population_total": 1656,
            "population_workingage": 1409
          }
        },
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E01004736",
      "links": {
        "html": "areas/E01004736.html",
        "self": "areas/E01004736"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "lsoa21",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E01004736/areatype",
            "self": "areas/E01004736/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E01004736/children",
            "self": "areas/E01004736/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E01004736/example_postcodes",
            "self": "areas/E01004736/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E02000977",
            "type": "areas"
          },
          "links": {
            "related": "areas/E01004736/parent",
            "self": "areas/E01004736/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E01004736/predecessor",
            "self": "areas/E01004736/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E01004736/successor",
            "self": "areas/E01004736/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [],
        "areachect": 28.16,
        "areaehect": 28.16,
        "areaihect": 0.0,
        "arealhect": 28.16,
        "child_count": 0,
        "child_counts": {},
        "code": "E00023938",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E00",
        "equivalents": {
          "ons": "00BKGQ0013"
        },
        "name": "",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E01004736",
        "predecessor": [
          "00BKGQ0013"
        ],
        "sort_order": "E00023938",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E00023938",
      "links": {
        "html": "areas/E00023938.html",
        "self": "areas/E00023938"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "oa21",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E00023938/areatype",
            "self": "areas/E00023938/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E00023938/children",
            "self": "areas/E00023938/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E00023938/example_postcodes",
            "self": "areas/E00023938/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E01004736",
            "type": "areas"
          },
          "links": {
            "related": "areas/E00023938/parent",
            "self": "areas/E00023938/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E00023938/predecessor",
            "self": "areas/E00023938/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E00023938/successor",
            "self": "areas/E00023938/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Strand, St James & Mayfair"
        ],
        "areachect": 251.46,
        "areaehect": 259.47,
        "areaihect": 0.0,
        "arealhect": 251.46,
        "child_count": 283,
        "child_counts": {
          "lsoa21": 4,
          "wz11": 279
        },
        "code": "E02000977",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Sun, 01 Aug 2004 00:00:00 GMT",
        "entity": "E02",
        "equivalents": {},
        "name": "Strand, St James & Mayfair",
        "name_welsh": null,
        "owner": "ONS",
        "parent": "E09000033",
        "predecessor": [],
        "sort_order": "E02000977",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E02000977",
      "links": {
        "html": "areas/E02000977.html",
        "self": "areas/E02000977"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "msoa21",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E02000977/areatype",
            "self": "areas/E02000977/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E02000977/children",
            "self": "areas/E02000977/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E02000977/example_postcodes",
            "self": "areas/E02000977/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E09000033",
            "type": "areas"
          },
          "links": {
            "related": "areas/E02000977/parent",
            "self": "areas/E02000977/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E02000977/predecessor",
            "self": "areas/E02000977/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E02000977/successor",
            "self": "areas/E02000977/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "North West London",
          "North West London Health and Care Partnership",
          "NHS North West London Integrated Care Board"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 9,
        "child_counts": {
          "ccg": 9
        },
        "code": "E54000027",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Fri, 01 Apr 2016 00:00:00 GMT",
        "entity": "E54",
        "equivalents": {
          "nhs": "QRV"
        },
        "name": "NHS North West London Integrated Care Board",
        "name_welsh": null,
        "owner": "ODS",
        "parent": "E40000003",
        "predecessor": [
          "E54000027",
          "E54000027"
        ],
        "sort_order": "E54000027",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": [
          "E54000027",
          "E54000027"
        ]
      },
      "id": "E54000027",
      "links": {
        "html": "areas/E54000027.html",
        "self": "areas/E54000027"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "icb",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E54000027/areatype",
            "self": "areas/E54000027/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E54000027/children",
            "self": "areas/E54000027/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E54000027/example_postcodes",
            "self": "areas/E54000027/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E40000003",
            "type": "areas"
          },
          "links": {
            "related": "areas/E54000027/parent",
            "self": "areas/E54000027/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E54000027",
              "type": "areas"
            },
            {
              "id": "E54000027",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E54000027/predecessor",
            "self": "areas/E54000027/relationships/predecessor"
          }
        },
        "successor": {
          "data": [
            {
              "id": "E54000027",
              "type": "areas"
            },
            {
              "id": "E54000027",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E54000027/successor",
            "self": "areas/E54000027/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "NHS North West London CCG",
          "NHS North West London ICB - W2U3Z"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E38000256",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Apr 2021 00:00:00 GMT",
        "entity": "E38",
        "equivalents": {
          "nhs": "W2U3Z"
        },
        "name": "NHS North West London ICB - W2U3Z",
        "name_welsh": null,
        "owner": "ODS",
        "parent": "E54000027",
        "predecessor": [
          "E38000020",
          "E38000031",
          "E38000048",
          "E38000070",
          "E38000074",
          "E38000082",
          "E38000084",
          "E38000202",
          "E38000256"
        ],
        "sort_order": "E38000256",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": [
          "E38000256"
        ]
      },
      "id": "E38000256",
      "links": {
        "html": "areas/E38000256.html",
        "self": "areas/E38000256"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "ccg",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E38000256/areatype",
            "self": "areas/E38000256/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E38000256/children",
            "self": "areas/E38000256/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E38000256/example_postcodes",
            "self": "areas/E38000256/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E54000027",
            "type": "areas"
          },
          "links": {
            "related": "areas/E38000256/parent",
            "self": "areas/E38000256/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E38000020",
              "type": "areas"
            },
            {
              "id": "E38000031",
              "type": "areas"
            },
            {
              "id": "E38000048",
              "type": "areas"
            },
            {
              "id": "E38000070",
              "type": "areas"
            },
            {
              "id": "E38000074",
              "type": "areas"
            },
            {
              "id": "E38000082",
              "type": "areas"
            },
            {
              "id": "E38000084",
              "type": "areas"
            },
            {
              "id": "E38000202",
              "type": "areas"
            },
            {
              "id": "E38000256",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E38000256/predecessor",
            "self": "areas/E38000256/relationships/predecessor"
          }
        },
        "successor": {
          "data": [
            {
              "id": "E38000256",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E38000256/successor",
            "self": "areas/E38000256/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": false,
        "alternative_names": [
          "City of Westminster"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E63004916",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": "Tue, 16 Apr 2024 00:00:00 GMT",
        "date_start": "Thu, 01 Dec 2022 00:00:00 GMT",
        "entity": "E63",
        "equivalents": {},
        "name": "City of Westminster",
        "name_welsh": null,
        "owner": "OS",
        "parent": "E92000001",
        "predecessor": [],
        "sort_order": "E63004916",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": [
          "E63012036"
        ]
      },
      "id": "E63004916",
      "links": {
        "html": "areas/E63004916.html",
        "self": "areas/E63004916"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "E63",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E63004916/areatype",
            "self": "areas/E63004916/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E63004916/children",
            "self": "areas/E63004916/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E63004916/example_postcodes",
            "self": "areas/E63004916/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E92000001",
            "type": "areas"
          },
          "links": {
            "related": "areas/E63004916/parent",
            "self": "areas/E63004916/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E63004916/predecessor",
            "self": "areas/E63004916/relationships/predecessor"
          }
        },
        "successor": {
          "data": [
            {
              "id": "E63012036",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E63004916/successor",
            "self": "areas/E63004916/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "England Non-National Park Area"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E65000001",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Mon, 27 Feb 2023 00:00:00 GMT",
        "entity": "E65",
        "equivalents": {},
        "name": "England Non-National Park Area",
        "name_welsh": null,
        "owner": "ONS",
        "parent": null,
        "predecessor": [],
        "sort_order": "E65000001",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E65000001",
      "links": {
        "html": "areas/E65000001.html",
        "self": "areas/E65000001"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "E65",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E65000001/areatype",
            "self": "areas/E65000001/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E65000001/children",
            "self": "areas/E65000001/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E65000001/example_postcodes",
            "self": "areas/E65000001/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": null,
          "links": {
            "related": "areas/E65000001/parent",
            "self": "areas/E65000001/relationships/parent"
          }
        },
        "predecessor": {
          "data": null,
          "links": {
            "related": "areas/E65000001/predecessor",
            "self": "areas/E65000001/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E65000001/successor",
            "self": "areas/E65000001/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "City of Westminster"
        ],
        "areachect": null,
        "areaehect": null,
        "areaihect": null,
        "arealhect": null,
        "child_count": 0,
        "child_counts": {},
        "code": "E63012036",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Wed, 17 Apr 2024 00:00:00 GMT",
        "entity": "E63",
        "equivalents": {},
        "name": "City of Westminster",
        "name_welsh": null,
        "owner": "OS",
        "parent": "E92000001",
        "predecessor": [
          "E63004916"
        ],
        "sort_order": "E63012036",
        "statutory_instrument_id": null,
        "statutory_instrument_title": null,
        "successor": []
      },
      "id": "E63012036",
      "links": {
        "html": "areas/E63012036.html",
        "self": "areas/E63012036"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "E63",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E63012036/areatype",
            "self": "areas/E63012036/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E63012036/children",
            "self": "areas/E63012036/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E63012036/example_postcodes",
            "self": "areas/E63012036/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E92000001",
            "type": "areas"
          },
          "links": {
            "related": "areas/E63012036/parent",
            "self": "areas/E63012036/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E63004916",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E63012036/predecessor",
            "self": "areas/E63012036/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E63012036/successor",
            "self": "areas/E63012036/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "St James's"
        ],
        "areachect": 315.14,
        "areaehect": 343.32,
        "areaihect": 0.0,
        "arealhect": 315.14,
        "child_count": 0,
        "child_counts": {},
        "code": "E05013806",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 05 May 2022 00:00:00 GMT",
        "entity": "E05",
        "equivalents": {},
        "name": "St James's",
        "name_welsh": null,
        "owner": "DLUHC",
        "parent": "E09000033",
        "predecessor": [
          "E05000644"
        ],
        "sort_order": "E05013806",
        "statutory_instrument_id": "1224/2020",
        "statutory_instrument_title": "The City of Westminster (Electoral Changes) Order 2020",
        "successor": []
      },
      "id": "E05013806",
      "links": {
        "html": "areas/E05013806.html",
        "self": "areas/E05013806"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "ward",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E05013806/areatype",
            "self": "areas/E05013806/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E05013806/children",
            "self": "areas/E05013806/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E05013806/example_postcodes",
            "self": "areas/E05013806/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E09000033",
            "type": "areas"
          },
          "links": {
            "related": "areas/E05013806/parent",
            "self": "areas/E05013806/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "id": "E05000644",
              "type": "areas"
            }
          ],
          "links": {
            "related": "areas/E05013806/predecessor",
            "self": "areas/E05013806/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E05013806/successor",
            "self": "areas/E05013806/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "active": true,
        "alternative_names": [
          "Westminster"
        ],
        "areachect": 2147.72,
        "areaehect": 2203.01,
        "areaihect": 0.0,
        "arealhect": 2147.72,
        "child_count": 64,
        "child_counts": {
          "msoa21": 24,
          "ncp": 1,
          "par": 1,
          "ward": 38
        },
        "code": "E09000033",
        "ctry": "E92000001",
        "ctry_name": "England",
        "date_end": null,
        "date_start": "Thu, 01 Jan 2009 00:00:00 GMT",
        "entity": "E09",
        "equivalents": {
          "mhclg": "X5990",
          "nhs": "713",
          "ons": "00BK"
        },
        "name": "Westminster",
        "name_welsh": null,
        "owner": "DLUHC",
        "parent": "E12000007",
        "predecessor": [
          "00BK"
        ],
        "sort_order": "E09000033",
        "statutory_instrument_id": "1111/1001",
        "statutory_instrument_title": "GSS re-coding strategy",
        "successor": []
      },
      "id": "E09000033",
      "links": {
        "html": "areas/E09000033.html",
        "self": "areas/E09000033"
      },
      "relationships": {
        "areatype": {
          "data": {
            "id": "laua",
            "type": "areatypes"
          },
          "links": {
            "related": "areas/E09000033/areatype",
            "self": "areas/E09000033/relationships/areatype"
          }
        },
        "children": {
          "data": null,
          "links": {
            "related": "areas/E09000033/children",
            "self": "areas/E09000033/relationships/children"
          }
        },
        "example_postcodes": {
          "data": [],
          "links": {
            "related": "areas/E09000033/example_postcodes",
            "self": "areas/E09000033/relationships/example_postcodes"
          }
        },
        "parent": {
          "data": {
            "id": "E12000007",
            "type": "areas"
          },
          "links": {
            "related": "areas/E09000033/parent",
            "self": "areas/E09000033/relationships/parent"
          }
        },
        "predecessor": {
          "data": [
            {
              "errors": [
                {
                  "detail": "resource could not be found",
                  "status": "404",
                  "title": "resource not found"
                }
              ]
            }
          ],
          "links": {
            "related": "areas/E09000033/predecessor",
            "self": "areas/E09000033/relationships/predecessor"
          }
        },
        "successor": {
          "data": null,
          "links": {
            "related": "areas/E09000033/successor",
            "self": "areas/E09000033/relationships/successor"
          }
        }
      },
      "type": "areas"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2979",
        "gridgb1e": "529500",
        "gridgb1m": "5295000179500",
        "gridgb1n": "0179500",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.499613,
        "location": {
          "lat": 51.499613,
          "lon": -0.135712
        },
        "long": -0.135712,
        "name": "Westminster",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0077107",
        "place22cd": "IPN0077107",
        "place23cd": "IPN0077107",
        "placeid": "73519",
        "placesort": "westminster",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "58942",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0077107",
      "links": {
        "html": "places/IPN0077107.html",
        "self": "places/IPN0077107"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0077107/areas",
            "self": "places/IPN0077107/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0077107/nearest_places",
            "self": "places/IPN0077107/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0077107/nearest_postcodes",
            "self": "places/IPN0077107/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2879",
        "gridgb1e": "528658",
        "gridgb1m": "5286580179429",
        "gridgb1n": "0179429",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.499167,
        "location": {
          "lat": 51.499167,
          "lon": -0.147862
        },
        "long": -0.147862,
        "name": "Buckingham Palace",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0010626",
        "place22cd": "IPN0010626",
        "place23cd": "IPN0010626",
        "placeid": "10109",
        "placesort": "buckinghampalace",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "7934",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0010626",
      "links": {
        "html": "places/IPN0010626.html",
        "self": "places/IPN0010626"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0010626/areas",
            "self": "places/IPN0010626/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0010626/nearest_places",
            "self": "places/IPN0010626/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0010626/nearest_postcodes",
            "self": "places/IPN0010626/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2879",
        "gridgb1e": "528593",
        "gridgb1m": "5285930179793",
        "gridgb1n": "0179793",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.502453,
        "location": {
          "lat": 51.502453,
          "lon": -0.148665
        },
        "long": -0.148665,
        "name": "Green Park",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0029858",
        "place22cd": "IPN0029858",
        "place23cd": "IPN0029858",
        "placeid": "28358",
        "placesort": "greenpark",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "22376",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0029858",
      "links": {
        "html": "places/IPN0029858.html",
        "self": "places/IPN0029858"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0029858/areas",
            "self": "places/IPN0029858/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0029858/nearest_places",
            "self": "places/IPN0029858/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0029858/nearest_postcodes",
            "self": "places/IPN0029858/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2879",
        "gridgb1e": "528750",
        "gridgb1m": "5287500179250",
        "gridgb1n": "0179250",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.497538,
        "location": {
          "lat": 51.497538,
          "lon": -0.146602
        },
        "long": -0.146602,
        "name": "Grosvenor",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0030177",
        "place22cd": "IPN0030177",
        "place23cd": "IPN0030177",
        "placeid": "28665",
        "placesort": "grosvenor",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "22804",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0030177",
      "links": {
        "html": "places/IPN0030177.html",
        "self": "places/IPN0030177"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0030177/areas",
            "self": "places/IPN0030177/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0030177/nearest_places",
            "self": "places/IPN0030177/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0030177/nearest_postcodes",
            "self": "places/IPN0030177/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2878",
        "gridgb1e": "528900",
        "gridgb1m": "5289000178950",
        "gridgb1n": "0178950",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.494808,
        "location": {
          "lat": 51.494808,
          "lon": -0.144552
        },
        "long": -0.144552,
        "name": "Victoria",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0074526",
        "place22cd": "IPN0074526",
        "place23cd": "IPN0074526",
        "placeid": "71053",
        "placesort": "victoria",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "56909",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0074526",
      "links": {
        "html": "places/IPN0074526.html",
        "self": "places/IPN0074526"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0074526/areas",
            "self": "places/IPN0074526/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0074526/nearest_places",
            "self": "places/IPN0074526/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0074526/nearest_postcodes",
            "self": "places/IPN0074526/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2879",
        "gridgb1e": "528363",
        "gridgb1m": "5283630179760",
        "gridgb1n": "0179760",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.502209,
        "location": {
          "lat": 51.502209,
          "lon": -0.151989
        },
        "long": -0.151989,
        "name": "Hyde Park Corner",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0036727",
        "place22cd": "IPN0036727",
        "place23cd": "IPN0036727",
        "placeid": "34957",
        "placesort": "hydeparkcorner",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "27941",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0036727",
      "links": {
        "html": "places/IPN0036727.html",
        "self": "places/IPN0036727"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0036727/areas",
            "self": "places/IPN0036727/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0036727/nearest_places",
            "self": "places/IPN0036727/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0036727/nearest_postcodes",
            "self": "places/IPN0036727/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2980",
        "gridgb1e": "529500",
        "gridgb1m": "5295000180350",
        "gridgb1n": "0180350",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.507252,
        "location": {
          "lat": 51.507252,
          "lon": -0.1354
        },
        "long": -0.1354,
        "name": "St James's",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0066656",
        "place22cd": "IPN0066656",
        "place23cd": "IPN0066656",
        "placeid": "63461",
        "placesort": "stjamess",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "50054",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0066656",
      "links": {
        "html": "places/IPN0066656.html",
        "self": "places/IPN0066656"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0066656/areas",
            "self": "places/IPN0066656/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0066656/nearest_places",
            "self": "places/IPN0066656/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0066656/nearest_postcodes",
            "self": "places/IPN0066656/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2979",
        "gridgb1e": "529904",
        "gridgb1m": "5299040179727",
        "gridgb1n": "0179727",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.501561,
        "location": {
          "lat": 51.501561,
          "lon": -0.129812
        },
        "long": -0.129812,
        "name": "St James's Park",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0066661",
        "place22cd": "IPN0066661",
        "place23cd": "IPN0066661",
        "placeid": "63466",
        "placesort": "stjamesspark",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "50053",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0066661",
      "links": {
        "html": "places/IPN0066661.html",
        "self": "places/IPN0066661"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0066661/areas",
            "self": "places/IPN0066661/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0066661/nearest_places",
            "self": "places/IPN0066661/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0066661/nearest_postcodes",
            "self": "places/IPN0066661/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ3079",
        "gridgb1e": "530022",
        "gridgb1m": "5300220179489",
        "gridgb1n": "0179489",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.499395,
        "location": {
          "lat": 51.499395,
          "lon": -0.1282
        },
        "long": -0.1282,
        "name": "Westminster Abbey",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0077110",
        "place22cd": "IPN0077110",
        "place23cd": "IPN0077110",
        "placeid": "73521",
        "placesort": "westminsterabbey",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "58940",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0077110",
      "links": {
        "html": "places/IPN0077110.html",
        "self": "places/IPN0077110"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0077110/areas",
            "self": "places/IPN0077110/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0077110/nearest_places",
            "self": "places/IPN0077110/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0077110/nearest_postcodes",
            "self": "places/IPN0077110/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    },
    {
      "attributes": {
        "areas": {
          "bua22": null,
          "cty": "E13000001",
          "eer": "E15000007",
          "hlth": "E18000007",
          "laua": "E09000033",
          "parish": null,
          "park": null,
          "pcon": "E14000639",
          "pfa": "E23000001",
          "rgd": "E28000071",
          "rgn": "E12000007",
          "ward": null
        },
        "bua22cd": null,
        "ced21cd": null,
        "ced22cd": null,
        "ced23cd": null,
        "country": "Great Britain",
        "ctry21nm": "England",
        "ctry22nm": "England",
        "ctry23nm": "England",
        "cty21cd": "E13000001",
        "cty21nm": "Inner London",
        "cty22cd": "E13000001",
        "cty22nm": "Inner London",
        "cty61nm": "London",
        "cty91nm": "Greater London",
        "ctyhistnm": "Middlesex",
        "ctyltnm": "Greater London",
        "descnm": "LOC",
        "eer21cd": "E15000007",
        "eer21nm": "London",
        "eer22cd": "E15000007",
        "eer22nm": "London",
        "grid1km": "TQ2880",
        "gridgb1e": "528750",
        "gridgb1m": "5287500180600",
        "gridgb1n": "0180600",
        "hlth21cd": "E18000007",
        "hlth21nm": "London",
        "hlth22cd": "E18000007",
        "hlth22nm": "London",
        "lad21cd": "E09000033",
        "lad21desc": "LONB",
        "lad21nm": "Westminster",
        "lad22cd": "E09000033",
        "lad22desc": "LONB",
        "lad22nm": "Westminster",
        "lad23desc": "LONB",
        "lad61desc": "MB",
        "lad61nm": "Westminster",
        "lad91desc": "LONB",
        "lad91nm": "City of Westminster",
        "lat": 51.50967,
        "location": {
          "lat": 51.50967,
          "lon": -0.14611
        },
        "long": -0.14611,
        "name": "Berkeley",
        "npark21cd": null,
        "npark21nm": null,
        "npark22cd": null,
        "npark22nm": null,
        "par21cd": null,
        "par22cd": null,
        "pcon21cd": "E14000639",
        "pcon21nm": "Cities of London and Westminster",
        "pcon22cd": "E14000639",
        "pcon22nm": "Cities of London and Westminster",
        "pfa21cd": "E23000001",
        "pfa21nm": "Metropolitan Police",
        "pfa22cd": "E23000001",
        "pfa22nm": "Metropolitan Police",
        "place21cd": "IPN0005349",
        "place22cd": "IPN0005349",
        "place23cd": "IPN0005349",
        "placeid": "5097",
        "placesort": "berkeley",
        "regd21cd": "E28000071",
        "regd21nm": "Westminster",
        "regd22cd": "E28000071",
        "regd22nm": "Westminster",
        "rgn21cd": "E12000007",
        "rgn21nm": "London",
        "rgn22cd": "E12000007",
        "rgn22nm": "London",
        "splitind": false,
        "tempcode": "3968",
        "type": "Locality",
        "wd21cd": null,
        "wd22cd": null
      },
      "id": "IPN0005349",
      "links": {
        "html": "places/IPN0005349.html",
        "self": "places/IPN0005349"
      },
      "relationships": {
        "areas": {
          "data": null,
          "links": {
            "related": "places/IPN0005349/areas",
            "self": "places/IPN0005349/relationships/areas"
          }
        },
        "nearest_places": {
          "data": null,
          "links": {
            "related": "places/IPN0005349/nearest_places",
            "self": "places/IPN0005349/relationships/nearest_places"
          }
        },
        "nearest_postcodes": {
          "data": null,
          "links": {
            "related": "places/IPN0005349/nearest_postcodes",
            "self": "places/IPN0005349/relationships/nearest_postcodes"
          }
        }
      },
      "type": "places"
    }
  ],
  "links": {
    "html": "postcodes/SW1A+1AA.html",
    "self": "postcodes/SW1A+1AA"
  }
}
JSON;
}
