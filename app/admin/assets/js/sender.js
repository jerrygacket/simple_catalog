function http_build_query(jsonObj) {
    const keys = Object.keys(jsonObj);
    const values = keys.map(key => jsonObj[key]);

    return keys
        .map((key, index) => {
            return `${key}=${values[index]}`;
        })
        .join("&");
}

async function sendGETRequest(url, data = {}) {
    var requestFields = {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
    };
    let queryString = http_build_query(data);
    console.log(baseUri+url+'?'+queryString);
    let result = {};
    await fetch(baseUri+url+'?'+queryString, requestFields)
        .then((response) => {
            result = response.json();
        })
        .catch((error) => console.log(error));

    return result;
}

async function sendPOSTRequest(url, data = {}) {
    var requestFields = {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    };
    console.log(baseUri+url);

    let result = {};
    await fetch(baseUri+url, requestFields)
        .then((response) => {
            result = response.json();
        }).catch((error) => console.log(error));

    return result;
}

async function sendDELETERequest(url, data = {}) {
    var requestFields = {
        method: 'DELETE',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    };
    console.log(baseUri+url);

    let result = {};
    await fetch(baseUri+url, requestFields)
        .then((response) => {
            console.log(response.text());
        }).catch((error) => console.log(error));

    return result;
}