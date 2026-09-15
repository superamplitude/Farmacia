(()=>{
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const box=document.querySelector('#ai-chat');
  if(!box)return;

  const head=box.querySelector('.chat-head');
  const log=box.querySelector('.chat-log');
  const form=box.querySelector('form');
  const input=form?.querySelector('input');
  if(!head||!log||!form||!input)return;

  let closeBtn=head.querySelector('.chat-close');
  if(!closeBtn){
    closeBtn=document.createElement('button');
    closeBtn.type='button';
    closeBtn.className='chat-close';
    closeBtn.setAttribute('aria-label','Fechar chat');
    closeBtn.setAttribute('title','Fechar chat');
    closeBtn.textContent='×';
    head.appendChild(closeBtn);
  }

  let openBtn=document.querySelector('#ai-chat-open');
  if(!openBtn){
    openBtn=document.createElement('button');
    openBtn.type='button';
    openBtn.id='ai-chat-open';
    openBtn.className='chat-open';
    openBtn.textContent='Chat';
    document.body.appendChild(openBtn);
  }

  const setOpen=open=>{
    box.hidden=!open;
    openBtn.hidden=open;
    try{localStorage.setItem('farmacia_chat_open',open?'1':'0')}catch(_){ }
    if(open)setTimeout(()=>input.focus(),0);
  };

  let initial=true;
  try{initial=localStorage.getItem('farmacia_chat_open')!=='0'}catch(_){ }
  setOpen(initial);
  closeBtn.addEventListener('click',()=>setOpen(false));
  openBtn.addEventListener('click',()=>setOpen(true));
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!box.hidden)setOpen(false)});

  const savedZip=(()=>{try{return(localStorage.getItem('farmacia_cep')||'').replace(/\D/g,'').slice(0,8)}catch{return''}})();
  if(savedZip.length===8)document.querySelectorAll('input[name="zip"]').forEach(i=>{if(!i.value)i.value=savedZip});

  const safeUrl=v=>{try{const u=new URL(String(v),location.origin);return['http:','https:'].includes(u.protocol)?esc(u.href):''}catch{return''}};
  const add=(who,html)=>{const d=document.createElement('div');d.className='msg '+who;d.innerHTML=html;log.appendChild(d);log.scrollTop=log.scrollHeight};

  form.addEventListener('submit',async e=>{
    e.preventDefault();
    const q=input.value.trim();
    if(!q)return;
    add('me',esc(q));
    input.value='';
    add('ai','Pesquisando…');
    const loading=log.lastElementChild;
    try{
      const r=await fetch(box.dataset.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:q})});
      const data=await r.json();
      loading?.remove();
      if(!r.ok)throw new Error(data.error||'Falha');
      let html='<p>'+esc(data.answer||'')+'</p>';
      if(data.medications?.length){
        html+='<div class="chat-products">'+data.medications.map(m=>{
          const img=safeUrl(m.image);
          return `<div class="chat-product">${img?`<img src="${img}" alt="">`:''}<div><b>${esc(m.name)}</b><small>${esc(m.active_ingredient||'')}</small>${m.available?`<span>R$ ${Number(m.price||0).toFixed(2).replace('.',',')}</span>`:'<span>Consultar disponibilidade</span>'}</div></div>`;
        }).join('')+'</div>';
      }
      add('ai',html);
    }catch(_){
      loading?.remove();
      add('ai','Não consegui concluir a consulta agora. Tente novamente ou fale com o farmacêutico.');
    }
  });
})();
