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
    * **Content:** 
        ```json
        {"sources": {
            "gate": [1482828600,1490604300], 
            "swi6": [1482828900,1490604600]
        }, "stored_output_formats": [], "stored_filters": []}
        ```
  
 * **Error Response:**
 
   * **Code:** 400 BAD REQUEST 
   * **Content:** 
        ```json 
        {"code": 400, "error": "400 - Bad Request. Probably wrong or not enough arguments."}
        ```
   OR
 
   * **Code:** 404 NOT FOUND 
   * **Content:** 
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
    {"start":1490484600,"end":1490652000,"step":600,"data":[
        {"legend":"flows_tcp_of_gate","data":{
            "1490484600":33.998333333333,
            "1490485200":37.005...
    ```
 
* **Error Response:**
  
    * **Code:** 400 BAD REQUEST
    * **Content:** 
        ```json
        {"code": 400, "error": "400 - Bad Request. Probably wrong or not enough arguments."}
        ```
  
     OR
  
    * **Code:** 404 NOT FOUND
    * **Content:** 
        ```json 
        {"code": 404, "error": "400 - Not found. "}
        ```

* **Sample Call:**

  ```Shell
  curl -g "http://localhost/nfsen-ng/api/graph?datestart=1490484000&dateend=1490652000&type=flows&sources[0]=gate&protocols[0]=tcp&protocols[1]=icmp"
  ```


More endpoints to come:
* `/api/graph_stats`
* `/api/flows`
* `/api/stats`