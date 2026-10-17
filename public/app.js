'use strict';
const {catalog, operators} = JSON.parse(document.getElementById('app-data').textContent);
const columns = catalog?.columns ?? [];
const $ = id => document.getElementById(id);
const labels = {equals:'equals',not_equals:'not equals',contains:'contains',not_contains:'does not contain',gt:'greater than',gte:'greater than or equal',lt:'less than',lte:'less than or equal',between:'between'};
let rules=[], appliedRules=[], search='', page=1, lastPage=1, sort='_row', direction='asc', perPage=25, request=null, sequence=0;
let visible = new Set(columns.map(c=>c.key));
function el(tag, text, className) {const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(className)n.className=className;return n;}
function select(options, value, label, className) {const s=el('select',undefined,className);s.setAttribute('aria-label',label);for(const [key,text] of options){const o=el('option',text);o.value=key;s.append(o);}s.value=value;return s;}
function preview(){
 $('rule-count').textContent=`${rules.length} condition${rules.length===1?'':'s'}`;
 $('no-rules').hidden=rules.length>0;
 $('query-preview').textContent=rules.length?rules.map((r,i)=>`${i?r.join+' ':''}${columns.find(c=>c.key===r.column)?.name} ${labels[r.operator]} ${JSON.stringify(r.value)}${r.operator==='between'?' AND '+JSON.stringify(r.value2):''}`).join(' '):'All products';
 $('apply').textContent=JSON.stringify(rules)===JSON.stringify(appliedRules)?'Apply filters →':'Apply filters •';
}
function renderRules(){
 $('rules').replaceChildren();
 rules.forEach((r,i)=>{
  const row=el('div',undefined,'rule');
  if(i===0)row.append(el('span','Where','where-label'));
  else{const join=select([['AND','AND'],['OR','OR']],r.join,`Join condition ${i+1}`,'join');join.onchange=()=>{r.join=join.value;preview();};row.append(join);}
  const col=columns.find(c=>c.key===r.column);
  const cs=select(columns.map(c=>[c.key,c.name]),r.column,`Column for condition ${i+1}`,'column-select');
  cs.onchange=()=>{r.column=cs.value;r.operator=operators[columns.find(c=>c.key===r.column).type][0];r.value='';r.value2='';renderRules();};row.append(cs);
  const os=select(operators[col.type].map(o=>[o,labels[o]]),r.operator,`Operation for condition ${i+1}`,'operator-select');
  os.onchange=()=>{r.operator=os.value;renderRules();};row.append(os);
  const makeInput=(field,label)=>{const n=el('input',undefined,'value-input');n.type=col.type==='number'?'number':col.type==='date'?'date':'text';if(col.type==='number')n.step='any';n.value=r[field]??'';n.placeholder=label;n.required=true;n.setAttribute('aria-label',`${label} for condition ${i+1}`);n.oninput=()=>{r[field]=n.value;preview();};n.onkeydown=e=>{if(e.key==='Enter')apply();};return n;};
  row.append(makeInput('value',r.operator==='between'?'From':'Enter a value…'));
  if(r.operator==='between')row.append(el('span','and','between-and'),makeInput('value2','To'));
  const remove=el('button','×','icon-button');remove.setAttribute('aria-label',`Remove condition ${i+1}`);remove.onclick=()=>{rules.splice(i,1);renderRules();};row.append(remove);$('rules').append(row);
 });preview();$('add-rule').disabled=!catalog||rules.length>=50;
}
$('add-rule').onclick=()=>{const c=columns[1];if(!c)return;rules.push({column:c.key,operator:operators[c.type][0],join:'AND',value:'',value2:''});renderRules();$('rules').lastChild.querySelector('input').focus();};
function apply(){for(const input of $('rules').querySelectorAll('input'))if(!input.reportValidity())return;appliedRules=structuredClone(rules);page=1;load();preview();}
$('apply').onclick=apply;
$('reset').onclick=()=>{rules=[];appliedRules=[];search='';$('search').value='';page=1;renderRules();load();};
function renderHead(){const tr=el('tr');for(const c of columns.filter(c=>visible.has(c.key))){const th=el('th');const b=el('button',c.name);b.append(el('span',sort===c.key?(direction==='asc'?'↑':'↓'):'↕'));b.title=`Sort by ${c.name}${c.multiple?' (first value)':''}`;b.onclick=()=>{direction=sort===c.key&&direction==='asc'?'desc':'asc';sort=c.key;page=1;load();};th.append(b);th.setAttribute('aria-sort',sort===c.key?(direction==='asc'?'ascending':'descending'):'none');tr.append(th);}$('table-head').replaceChildren(tr);}
function displayValue(value, column) {
 if (value === null) return '—';
 const format = v => column.type === 'number' ? Number(v).toLocaleString(undefined, {maximumFractionDigits:12}) : String(v);
 return Array.isArray(value) ? value.map(format).join(' | ') : format(value);
}
function renderRows(data){$('table-body').replaceChildren();for(const r of data){const tr=el('tr');for(const c of columns.filter(c=>visible.has(c.key))){const val=r[c.key];const td=el('td',undefined,c.key==='c0'?'id-cell':c.key==='c1'?'product-name':c.type==='number'?'numeric':'');if(c.key==='c1')td.append(el('span',String(val??'').slice(0,1).toUpperCase(),'product-avatar'));td.append(document.createTextNode(displayValue(val,c)));tr.append(td);}$('table-body').append(tr);}}
let currentRows=[];
function renderColumnOptions(){for(const c of columns){const label=el('label');const check=el('input');check.type='checkbox';check.checked=true;check.onchange=()=>{if(!check.checked&&visible.size===1){check.checked=true;return;}if(check.checked)visible.add(c.key);else visible.delete(c.key);renderHead();renderRows(currentRows);};label.append(check,document.createTextNode(c.name));$('column-options').append(label);}}
async function load(){
 if(!catalog){$('empty-state').hidden=false;$('apply').disabled=true;$('search').disabled=true;$('previous').disabled=true;$('next').disabled=true;$('per-page').disabled=true;return;}
 request?.abort();request=new AbortController();const token=++sequence;$('table-body').classList.add('loading');$('query-error').hidden=true;$('range-label').textContent='Loading products…';$('previous').disabled=true;$('next').disabled=true;
 try{
  const response=await fetch('/products/query',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({rules:appliedRules,search,page,per_page:perPage,sort,direction}),signal:request.signal});
  const result=await response.json();if(!response.ok)throw new Error(Object.values(result.errors??{}).flat()[0]??result.message??'Could not load products.');if(token!==sequence)return;
  lastPage=result.last_page;if(page>lastPage){page=lastPage;return load();}currentRows=result.data;renderHead();renderRows(currentRows);
  $('empty-state').hidden=result.total>0;if(!result.total){$('empty-title').textContent='No products match your selection';$('empty-description').textContent='Try another value or reset your filters to start again.';}
  for(const id of ['matching-count','result-count'])$(id).textContent=result.total.toLocaleString();
  $('matching-caption').textContent=appliedRules.length?`${appliedRules.length} condition${appliedRules.length===1?'':'s'} applied${search?' · name search active':''}`:search?'Name search active':'All products · no filters applied';
  $('table-title').firstChild.textContent=appliedRules.length||search?'Filtered products ':'All products ';
  $('range-label').textContent=result.total?`Showing ${result.from.toLocaleString()}–${result.to.toLocaleString()} of ${result.total.toLocaleString()} products`:'0 products';
  $('page-label').textContent=`${page} / ${lastPage}`;$('previous').disabled=page<=1;$('next').disabled=page>=lastPage;
 }catch(e){if(e.name!=='AbortError'&&token===sequence){$('query-error').textContent=e.message;$('query-error').hidden=false;$('range-label').textContent='Unable to load products';}}
 finally{if(token===sequence)$('table-body').classList.remove('loading');}
}
let debounce;$('search').oninput=()=>{clearTimeout(debounce);debounce=setTimeout(()=>{search=$('search').value;page=1;load();},250);};
$('per-page').onchange=()=>{perPage=Number($('per-page').value);page=1;load();};$('previous').onclick=()=>{if(page>1){page--;load();}};$('next').onclick=()=>{if(page<lastPage){page++;load();}};
for(const id of ['open-import','sidebar-import'])$(id).onclick=()=>$('import-dialog').showModal();
$('help-button').onclick=()=>$('help-dialog').showModal();
for(const button of document.querySelectorAll('[data-close]'))button.onclick=()=>$(button.dataset.close).close();
$('import-form').onsubmit=()=>{$('import-submit').disabled=true;$('import-submit').textContent='Importing…';};
renderRules();renderColumnOptions();renderHead();load();
