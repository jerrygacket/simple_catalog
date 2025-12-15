async function login(elem) {

    const formData = new FormData(elem);
    let data = {
        "username": formData.get('username'),
        "password": formData.get('password'),
        "remember": formData.get('remember'),
    };

    console.log(data);

    await sendPOSTRequest('/auth', data)
        .then((response) =>  {
            if (response.result.success) {
                window.location.reload();
            } else {
                alert('Wrong login');
            }
        })
        .catch((error) => console.log(error));

    return false;
}

function logout(elem) {
    sendDELETERequest('/auth')
        .then((response) =>  {
            window.location.reload();
        })
        .catch((error) => console.log(error));

    return false;
}

async function getItems(page) {
    let data = {};
    if (page > 1) {
        data = {"page": page};
    }

    await sendGETRequest('/items', data)
        .then((response) =>  {
            if (response.result.success) {
                outputStudents(response.result.result);
                const urlParams = new URLSearchParams(window.location.search);
                if (page > 1) {
                    urlParams.append("page", page);
                    let nextURL = window.location.pathname + "?" + urlParams.toString();
                    let nextTitle = window.location.title;
                    window.history.pushState('', nextTitle, nextURL);
                } else {
                    let nextURL = window.location.pathname;
                    let nextTitle = window.location.title;
                    window.history.pushState('', nextTitle, nextURL);
                }
            } else {
                alert('Wrong params');
            }
        })
        .catch((error) => console.log(error));

    return false;
}

function outputStudents(data) {
    let tbody = document.getElementById('table-body');
    tbody.innerHTML = '';

    data.data.forEach(function (element) {
        let tr = document.createElement('tr');
        let td1 = document.createElement('td');
        let td2 = document.createElement('td');
        td1.innerHTML = element.id;
        td2.innerHTML = element.name;
        tr.appendChild(td1);
        tr.appendChild(td2);
        tbody.appendChild(tr);
    });
    outputPager(data);
}

function outputPager(data) {
    let pager = document.getElementById('pager');
    pager.innerHTML = '';
    for (let i = 1; i <= data.meta.pages; i++) {
        let li = document.createElement('li');
        li.classList.add('page-item');
        let plink = document.createElement('span');
        if (data.meta.page === i) {
            li.classList.add('active');
            li.setAttribute("aria-current", "page");
        } else {
            plink = document.createElement('a');
            plink.setAttribute("href", "");
        }
        plink.setAttribute("data-page", i.toString());
        plink.classList.add("page-link");
        plink.innerHTML = i;
        li.appendChild(plink);
        pager.appendChild(li);
    }
    initPager();
}

function initPager() {
    let pages = document.getElementsByClassName('page-link');

    Array.from(pages).forEach((element) =>  {
        element.addEventListener('click', function handleClick(event) {
            event.preventDefault();
            getStudents(element.dataset.page);
            return false;
        });
    });
}

let tbody = document.getElementById('table-body');

if (tbody) {
    const urlParams = new URLSearchParams(window.location.search);

    if (urlParams.has('page')) {
        getStudents(urlParams.get('page'));
    } else {
        getStudents(1);
    }
}

