GET DATA WELDING
    Request URL
    http://sv-web-kap/pis/pisweb2.0/pis/wip/query_main2.php
    Request Method
    POST
    Status Code
    200 OK
    Remote Address
    10.59.236.124:80
    Referrer Policy
    strict-origin-when-cross-origin

    Payload :
    wip1 = 01010
    wip2 = 01030

    Expected Response :
    {
    "vin": "MHKAB1BC3TJ093385",
    "plandeliverydate": "09 Sep 2026",
    "modelcode": "D74A ",
    "ktsk": "A351RA-GBEFJ        ",
    "sfx": "7A",
    "destcode": "DOM  ",
    "colorcode": "X13 ",
    "colorname": "BLACK               ",
    "wipname": "OFF LINE WELDING",
    "scandate": "2026-09-08 08:16:33",
    "wipcode": "01030"
    }

GET DATA TOSO
    Request URL
    http://sv-web-kap/pis/pisweb2.0/pis/wip/query_main2.php
    Request Method
    POST
    Status Code
    200 OK
    Remote Address
    10.59.236.124:80
    Referrer Policy
    strict-origin-when-cross-origin

    Payload TOSO :
    wip1 = 01040
    wip2 = 02160

    payload next (PBS (Painting Buffer Stock)):
    wip1 = 02170

    Expected Response :
    {
        "vin": "MHKS6GK6JTJ044364",
        "plandeliverydate": "07 Sep 2026",
        "modelcode": "D52B ",
        "ktsk": "B401RS-GQZFJ        ",
        "sfx": "7X",
        "destcode": "DOM  ",
        "colorcode": "W09 ",
        "colorname": "WHITE               ",
        "wipname": "BUFFER TO PBS",
        "scandate": "2026-09-08 13:10:37",
        "wipcode": "02160"
    }

    hasil dari 2 proses payload tadi di buat jadi 1 data. dimana di jadikan 1 dengan shopcode toso.


GET DATA ASSY
    Request URL
    http://sv-web-kap/pis/pisweb2.0/pis/wip/query_main2.php
    Request Method
    POST
    Status Code
    200 OK
    Remote Address
    10.59.236.124:80
    Referrer Policy
    strict-origin-when-cross-origin

    Payload ASSY :
    wip1 = 03010

    payload next (RU (Running Unit)):
    wip1 = 03020
    wip2 = 04020

    Expected Response :
    {
        "vin": "MHKS6GK6JTJ044364",
        "plandeliverydate": "07 Sep 2026",
        "modelcode": "D52B ",
        "ktsk": "B401RS-GQZFJ        ",
        "sfx": "7X",
        "destcode": "DOM  ",
        "colorcode": "W09 ",
        "colorname": "WHITE               ",
        "wipname": "BUFFER TO PBS",
        "scandate": "2026-09-08 13:10:37",
        "wipcode": "02160"
    }

    hasil dari 2 proses payload tadi di buat jadi 1 data. dimana di jadikan 1 dengan shopcode assy