# nfsen-ng
nfsen-ng is an in-place replacement for the ageing nfsen.

## API
The API is used by the frontend to retrieve data. 

### config
 * **URL**
    `/api/config`
 
 * **Method:**
    `GET`
   
 *  **URL Params**
     none
 
 * **Success Response:**
 
    * **Code:** 200 
     **Content:** 
        ```json
        {"sources": {
            "gate": [1482828600,1490604300], 
            "swi6": [1482828900,1490604600]
        }, "stored_output_formats": [], "stored_filters": []}
        ```
  
 * **Error Response:**
 
   * **Code:** 400 BAD REQUEST 
     **Content:** 
        ```json 
        {"code": 400, "error": "400 - Bad Request. Probably wrong or not enough arguments."}
        ```
    OR
 
    * **Code:** 404 NOT FOUND 
     **Content:** 
        ```json
        {"code": 404, "error": "400 - Not found. "}
        ```
 
 * **Sample Call:**
    ```Shell
    curl localhost/nfsen-ng/api/config
    ```
 
### graph
* **URL**
  `/api/graph?datestart=1490484000&dateend=1490652000&type=flows&sources[0]=gate&protocols[0]=tcp&protocols[1]=icmp`

* **Method:**
  
  `GET`
  
*  **URL Params**
    * `datestart=[integer]` Unix timestamp
    * `dateend=[integer]` Unix timestamp
    * `type=[string]` Type of data to show: flows/packets/bytes
    * `sources=[array]` 
    * `protocols=[array]`
    
    There can't be multiple sources and multiple protocols both. Either one source and multiple protocols, or one protocol and multiple sources.

* **Success Response:**
  
  * **Code:** 200
    **Content:** 
    ```json 
    {"data": {
      "1490562300":[2.1666666667,94.396666667],
      "1490562600":[1.0466666667,72.976666667],...
    },"start":1490562300,"end":1490590800,"step":300,"legend":["swi6_flows_tcp","gate_flows_tcp"]}
    ```
 
* **Error Response:**
  * **Error Response:**
  
    * **Code:** 400 BAD REQUEST <br />
      **Content:** 
        ```json
        {"code": 400, "error": "400 - Bad Request. Probably wrong or not enough arguments."}
        ```
  
     OR
  
    * **Code:** 404 NOT FOUND <br />
      **Content:** 
        ```json 
        {"code": 404, "error": "400 - Not found. "}
        ```

* **Sample Call:**

  ```Shell
  curl -g "http://localhost/nfsen-ng/api/graph?datestart=1490484000&dateend=1490652000&type=flows&sources[0]=gate&protocols[0]=tcp&protocols[1]=icmp"
  ```

### flows
* **URL**
  `/api/flows?datestart=1482828600&dateend=1490604300&sources[0]=gate&sources[1]=swi6&filter=&limit=100&aggregate[]=srcip&sort=&output[format]=auto`

* **Method:**
  
  `GET`
  
*  **URL Params**
    * `datestart=[integer]` Unix timestamp
    * `dateend=[integer]` Unix timestamp
    * `sources=[array]` 
    * `filter=[string]` pcap-syntaxed filter
    * `limit=[int]` max. returned rows
    * `aggregate=[array]` can be `bidirectional` or a valid nfdump aggregation string (e.g. `srcip4/24, dstport`), but not both at the same time
    * `sort=[string]` (will probably cease to exist, as ordering is done directly in aggregation) e.g. `tstart`
    * `output=[array]` can contain `[format] = auto|line|long|extended` and `[IPv6]` 

* **Success Response:**
  
  * **Code:** 200
    **Content:** 
    ```json 
    [["ts","td","sa","da","sp","dp","pr","ipkt","ibyt","opkt","obyt"],
    ["2017-03-27 10:40:46","0.000","85.105.45.96","0.0.0.0","0","0","","1","46","0","0"],
    ...
    ```
 
* **Error Response:**
  * **Error Response:**
  
    * **Code:** 400 BAD REQUEST <br />
      **Content:** 
        ```json
        {"code": 400, "error": "400 - Bad Request. Probably wrong or not enough arguments."}
        ```
  
     OR
  
    * **Code:** 404 NOT FOUND <br />
      **Content:** 
        ```json 
        {"code": 404, "error": "400 - Not found. "}
        ```

* **Sample Call:**

  ```Shell
  curl -g "http://localhost/nfsen-ng/api/flows?datestart=1482828600&dateend=1490604300&sources[0]=gate&sources[1]=swi6&filter=&limit=100&aggregate[]=srcip&sort=&output[format]=auto"
  ```

### stats
* **URL**
  `/api/stats?datestart=1482828600&dateend=1490604300&sources[0]=gate&sources[1]=swi6&for=dstip&filter=&top=10&limit=100&aggregate[]=srcip&sort=&output[format]=auto`

* **Method:**
  
  `GET`
  
*  **URL Params**
    * `datestart=[integer]` Unix timestamp
    * `dateend=[integer]` Unix timestamp
    * `sources=[array]` 
    * `filter=[string]` pcap-syntaxed filter
    * `top=[int]` return top N rows
    * `for=[string]` field to get the statistics for. with optional ordering field as suffix, e.g. `ip/flows`
    * `limit=[string]` limit output to records above or below of `limit` e.g. `500K`
    * `output=[array]` can contain `[IPv6]` 

* **Success Response:**
  
  * **Code:** 200
    **Content:** 
    ```json 
    [
        ["Packet limit: > 100 packets"],
        ["ts","te","td","pr","val","fl","flP","ipkt","ipktP","ibyt","ibytP","ipps","ipbs","ibpp"],
        ["2017-03-27 10:38:20","2017-03-27 10:47:58","577.973","any","193.5.80.180","673","2.7","676","2.5","56581","2.7","1","783","83"],
        ...
    ]
    ```
 
* **Error Response:**
  * **Error Response:**
  
    * **Code:** 400 BAD REQUEST <br />
      **Content:** 
        ```json
        {"code": 400, "error": "400 - Bad Request. Probably wrong or not enough arguments."}
        ```
  
     OR
  
    * **Code:** 404 NOT FOUND <br />
      **Content:** 
        ```json 
        {"code": 404, "error": "400 - Not found. "}
        ```

* **Sample Call:**

  ```Shell
  curl -g "http://localhost/nfsen-ng/api/stats?datestart=1482828600&dateend=1490604300&sources[0]=gate&sources[1]=swi6&for=dstip&filter=&top=10&limit=100&aggregate[]=srcip&sort=&output[format]=auto"
  ```

More endpoints to come:
* `/api/graph_stats`