(()=>{
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

  const initCatalog=async()=>{
    const grid=document.querySelector('.grid');
    const empty=document.querySelector('.empty');
    const anchor=grid||empty;
    if(!anchor)return;
    const section=anchor.closest('section');
    const title=section?.querySelector('.section-title');
    if(!section||!title)return;

    try{
      const endpoint=new URL('api/categories.php',location.href);
      const r=await fetch(endpoint.toString(),{cache:'no-store'});
      if(!r.ok)return;
      const data=await r.json();
      if(!Array.isArray(data.categories)||!data.categories.length)return;

      const wrap=document.createElement('div');
      wrap.className='catalog-categories';
      wrap.setAttribute('aria-label','Categorias de medicamentos');
      Object.assign(wrap.style,{display:'flex',gap:'8px',overflowX:'auto',padding:'4px 0 14px',margin:'0 0 10px',scrollbarWidth:'thin'});

      const makeLink=(label,count,q,active=false)=>{
        const u=new URL(location.href);
        if(q)u.searchParams.set('q',q);else u.searchParams.delete('q');
        u.hash='';
        const a=document.createElement('a');
        a.href=u.pathname+(u.search||'');
        a.textContent=count===null?label:`${label} (${count})`;
        Object.assign(a.style,{display:'inline-flex',alignItems:'center',whiteSpace:'nowrap',padding:'9px 12px',borderRadius:'999px',border:'1px solid #d0d5dd',background:active?'#136f63':'#fff',color:active?'#fff':'#344054',fontSize:'13px',fontWeight:'700'});
        return a;
      };

      const current=(new URL(location.href)).searchParams.get('q')||'';
      wrap.appendChild(makeLink('Todos os produtos',Number(data.total||0),null,current===''));
      data.categories.forEach(c=>wrap.appendChild(makeLink(String(c.name||'Outros'),Number(c.total||0),String(c.name||''),current===String(c.name||''))));
      title.insertAdjacentElement('afterend',wrap);

      const counter=title.querySelector('span');
      if(counter&&Number(data.total)>0&&current==='')counter.textContent=`${Number(data.total).toLocaleString('pt-BR')} produtos cadastrados · exibindo os primeiros ${document.querySelectorAll('.card').length}`;
    }catch(_){/* catálogo continua funcional mesmo se o menu não carregar */}
  };

  initCatalog();

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
    openBtn.setAttribute('aria-label','Abrir chat');
    openBtn.setAttribute('title','Abrir chat');
    openBtn.textContent='Chat';
    openBtn.hidden=true;
    document.body.appendChild(openBtn);
  }

  const setOpen=(open)=>{
    box.hidden=!open;
    openBtn.hidden=open;
    if(open)setTimeout(()=>input.focus(),0);
  };

  closeBtn.addEventListener('click',()=>setOpen(false));
  openBtn.addEventListener('click',()=>setOpen(true));
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'&&!box.hidden)setOpen(false);
  });

  const safeUrl=v=>{try{const u=new URL(String(v),location.origin);return['http:','https:'].includes(u.protocol)?esc(u.href):''}catch{return''}};
  const add=(who,html)=>{const d=document.createElement('div');d.className='msg '+who;d.innerHTML=html;log.appendChild(d);log.scrollTop=log.scrollHeight;};

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
    }catch(err){
      loading?.remove();
      add('ai','Não consegui concluir a consulta agora. Tente novamente ou fale com o farmacêutico.');
    }
  });
})();
