'use strict';
const resetCode=new URLSearchParams(location.hash.slice(1)).get('token');
if(resetCode && /^[a-f0-9]{64}$/.test(resetCode)) document.getElementById('reset_token').value=resetCode;
if(location.hash) history.replaceState(null,'',location.pathname);
