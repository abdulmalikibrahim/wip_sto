BASE URL
Request URL
http://10.59.225.35/Web_AndonPCD/AndonWIPProd/GetData/
Request Method
POST
Status Code
200 OK
Remote Address
10.59.225.35:80
Referrer Policy
strict-origin-when-cross-origin
content-type
application/json; charset=utf-8
date
Tue, 08 Sep 2026 07:04:07 GMT
server
Microsoft-IIS/10.0
transfer-encoding
chunked
x-powered-by
ASP.NET
accept
application/json, text/javascript, */*; q=0.01
accept-encoding
gzip, deflate
accept-language
id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7,fr;q=0.6,ru;q=0.5,ms;q=0.4
connection
keep-alive
content-length
84
content-type
application/json; charset=UTF-8
cookie
UserLogin=userANDON; webbase_AndonPCD=%2FWeb_AndonPCD; webbase_PCD=%2FWeb_PCD; webbase_Welding=%2FWeb_Welding; webbase_Hrgm=%2FWeb_Harigami; webbase_Toso=%2FWeb_Toso; webbase_Common=%2FWeb_Common; jwtCookie=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJuYW1laWQiOiJ1c2VyQU5ET04iLCJuYW1lIjoiMjM2OEUwNGIgMDYwNiA0MEUwIDlEOGQgRmNhODY0MmM1MDQ5IiwianRpIjoiNjNhZWNkZDMtMGNhYi00Y2Q0LThmYmItMDI4MzVlODQzZmJiIiwibmJmIjoxNzg4ODUxMDMxLCJleHAiOjE4MjAzODcwMzEsImlzcyI6IkpXVEF1dGhlbnRpY2F0aW9uU2VydmVyIiwiYXVkIjoiSldUU2VydmljZVBvc3RtYW5DbGllbnQifQ.kAojs-8fjswGDz0EW88A5MTaOEf-pjxk6KkSFUKJJgE; UserName=2368E04b%200606%2040E0%209D8d%20Fca8642c5049; UserGroupID=0be15612X7cbfX4d46X8f61X1028aaeaffff
dnt
1
host
10.59.225.35
origin
http://10.59.225.35
referer
http://10.59.225.35/Web_AndonPCD/AndonWIPProd/Index
user-agent
Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36
x-requested-with
XMLHttpRequest

GET DATA WELDING
    PAYLOAD :
    {
        "SectionCode": "W",
        "TPCode": "",
        "ModelCode": "",
        "ModelName": "",
        "UserID": "userANDON"
    }

    Expected Response :
    {
        "ID": "0",
        "Message": "Success",
        "Contents": [
            {
                "Code": null,
                "Description": null,
                "DataCount": null,
                "No": "1",
                "Ktsk": "A350LA-GBEEF",
                "VIN": "MHKAA1BC1TJ074191",
                "Suffix": "VZ",
                "Model": "D74A ",
                "PlanDelDate": "02 Sep 2026",
                "Destination": "98111",
                "Color": "GRAY ME.",
                "ColorCode": "1G3",
                "LastScan": "",
                "ScanDate": "08 Sep 2026 11:41:54",
                "ShopCode": "MF"
            },
            {
                "Code": null,
                "Description": null,
                "DataCount": null,
                "No": "2",
                "Ktsk": "W100RE-LBDFJ",
                "VIN": "MHKAA1BY3TJ022833",
                "Suffix": "E8",
                "Model": "D26A ",
                "PlanDelDate": "10 Sep 2026",
                "Destination": "DOM  ",
                "Color": "WHITE               ",
                "ColorCode": "W09",
                "LastScan": "",
                "ScanDate": "08 Sep 2026 13:40:48",
                "ShopCode": "MF"
            },
        ]
    }

GET DATA TOSO (TOSO & PBS)
    PAYLOAD TOSO :
    {
        "SectionCode": "T",
        "TPCode": "",
        "ModelCode": "",
        "ModelName": "",
        "UserID": "userANDON"
    }

    PAYLOAD PBS :
    {
        "SectionCode": "PBS",
        "TPCode": "",
        "ModelCode": "",
        "ModelName": "",
        "UserID": "userANDON"
    }

    Expexted Response :
    {
        "ID": "0",
        "Message": "Success",
        "Contents": [
            {
                "Code": null,
                "Description": null,
                "DataCount": null,
                "No": "1",
                "Ktsk": "W100RE-LBDFJ",
                "VIN": "MHKAA1BY7TJ022687",
                "Suffix": "E8",
                "Model": "D26A ",
                "PlanDelDate": "09 Sep 2026",
                "Destination": "DOM  ",
                "Color": "WHITE               ",
                "ColorCode": "W09",
                "LastScan": "",
                "ScanDate": "08 Sep 2026 14:37:51",
                "ShopCode": "BFR PBS"
            }
        ]
    }

    Note : Gabungkan 2 hasil payload jadi 1 di shopcode toso

GET DATA ASSY (ASSY & RU (RUNNING UNIT))
    PAYLOAD ASSY :
    {
        "SectionCode": "A",
        "TPCode": "",
        "ModelCode": "",
        "ModelName": "",
        "UserID": "userANDON"
    }

    PAYLOAD RU :
    {
        "SectionCode": "RU",
        "TPCode": "",
        "ModelCode": "",
        "ModelName": "",
        "UserID": "userANDON"
    }

    Expected Response :
    {
        "ID": "0",
        "Message": "Success",
        "Contents": [
            {
                "Code": null,
                "Description": null,
                "DataCount": null,
                "No": "1",
                "Ktsk": "A250LA-GBVVF",
                "VIN": "MHKAA1BA9TJ197221",
                "Suffix": "RQ",
                "Model": "D55L ",
                "PlanDelDate": "07 Sep 2026",
                "Destination": "97153",
                "Color": "BLACK               ",
                "ColorCode": "X13",
                "LastScan": "",
                "ScanDate": "07 Sep 2026 07:26:58",
                "ShopCode": "RM"
            }
        ]
    }
    Note : Gabungkan 2 hasil payload jadi 1 di shopcode assy


Note All : untuk dapatkan data wip di kap2 ini sedikit berbeda seperti nya, karena ada jwt token, sehingga mungkin saja perlu membuat jwt token terlebih dahulu, tapi ini asumsi saya saja, jika tidak perlu maka aman saja langsung gas. 