(()=>{
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const qs=(s,r=document)=>r.querySelector(s);
  const qsa=(s,r=document)=>[...r.querySelectorAll(s)];

  const currentSearch=()=>new URL(location.href).searchParams.get('q')||'';
  const searchUrl=q=>{
    const u=new URL(location.href);
    if(q)u.searchParams.set('q',q);else u.searchParams.delete('q');
    u.hash='';
    return u.pathname+(u.search||'');
  };

  const curated=[
    ['Medicamentos',''],
    ['Genéricos','genérico'],
    ['Dor e febre','analgésico'],
    ['Gripe e resfriado','gripe'],
    ['Alergias','antialérgico'],
    ['Pressão alta','anti-hipertensivo'],
    ['Diabetes','antidiabético'],
    ['Colesterol','colesterol'],
    ['Saúde da mulher','ginecologia'],
    ['Digestão','gastro'],
    ['Respiratórios','respiratório'],
    ['Vitaminas','vitamina']
  ];

  const enhanceStorefront=()=>{
    const header=qs('header');
    const top=qs('.top',header||document);
    if(!header||!top||header.dataset.enhanced==='1')return;
    header.dataset.enhanced='1';

    const brand=qs('strong',top)?.textContent?.trim()||'Farmácia SuperAmplitude';
    const cartText=qsa('nav a',top).find(a=>/Carrinho/i.test(a.textContent||''))?.textContent?.trim()||'Carrinho';
    const adminHref=qsa('nav a',top).find(a=>/Painel/i.test(a.textContent||''))?.getAttribute('href')||'admin.php';

    const utility=document.createElement('div');
    utility.className='store-utility';
    utility.innerHTML=`<div class="wrap utility-inner"><span>Atendimento farmacêutico e delivery</span><div><a href="${esc(adminHref)}">Área administrativa</a><a href="#carrinho">${esc(cartText)}</a></div></div>`;
    header.insertBefore(utility,top);

    top.classList.add('store-mainbar');
    top.innerHTML=`
      <a class="store-brand" href="${esc(searchUrl(''))}" aria-label="Página inicial"><span class="brand-mark">+</span><span><b>${esc(brand)}</b><small>Cuidado, conveniência e segurança</small></span></a>
      <form class="store-search" method="get" action="${esc(location.pathname)}">
        <label class="sr-only" for="store-search-input">Buscar produto</label>
        <input id="store-search-input" name="q" value="${esc(currentSearch())}" placeholder="O que você precisa? Ex.: dipirona, losartana, vitamina..." autocomplete="off">
        <button type="submit">Buscar</button>
      </form>
      <button class="zip-trigger" type="button" aria-expanded="false"><span class="zip-icon">⌖</span><span><small>Entregar em</small><b class="zip-label">Informe seu CEP</b></span></button>
      <a class="cart-shortcut" href="#carrinho" aria-label="Abrir carrinho"><span>🛒</span><b>${esc(cartText.replace(/^Carrinho\s*/i,''))||'Carrinho'}</b></a>`;

    const zipPanel=document.createElement('div');
    zipPanel.className='zip-panel';
    zipPanel.hidden=true;
    zipPanel.innerHTML='<form><strong>Calcule entrega pelo CEP</strong><div><input inputmode="numeric" maxlength="9" placeholder="00000-000" aria-label="CEP"><button>Salvar</button></div><small>O CEP será usado para preencher sua entrega no checkout.</small></form>';
    header.appendChild(zipPanel);
    const zipTrigger=qs('.zip-trigger',top);
    const zipInput=qs('input',zipPanel);
    const savedZip=(localStorage.getItem('farmacia_cep')||'').replace(/\D/g,'').slice(0,8);
    if(savedZip.length===8){
      qs('.zip-label',top).textContent=savedZip.replace(/(\d{5})(\d{3})/,'$1-$2');
      zipInput.value=qs('.zip-label',top).textContent;
    }
    zipTrigger.addEventListener('click',()=>{
      zipPanel.hidden=!zipPanel.hidden;
      zipTrigger.setAttribute('aria-expanded',String(!zipPanel.hidden));
      if(!zipPanel.hidden)setTimeout(()=>zipInput.focus(),0);
    });
    qs('form',zipPanel).addEventListener('submit',e=>{
      e.preventDefault();
      const raw=zipInput.value.replace(/\D/g,'').slice(0,8);
      if(raw.length!==8){zipInput.setCustomValidity('Informe um CEP com 8 dígitos.');zipInput.reportValidity();return;}
      zipInput.setCustomValidity('');
      localStorage.setItem('farmacia_cep',raw);
      qs('.zip-label',top).textContent=raw.replace(/(\d{5})(\d{3})/,'$1-$2');
      qsa('input[name="zip"]').forEach(i=>{if(!i.value)i.value=raw;});
      zipPanel.hidden=true;
      zipTrigger.setAttribute('aria-expanded','false');
    });

    const nav=document.createElement('div');
    nav.className='store-categorybar';
    const inner=document.createElement('div');
    inner.className='wrap categorybar-inner';
    const all=document.createElement('button');
    all.type='button';all.className='all-categories';all.textContent='☰ Todas as categorias';
    inner.appendChild(all);
    curated.slice(1,9).forEach(([label,q])=>{
      const a=document.createElement('a');a.href=searchUrl(q);a.textContent=label;inner.appendChild(a);
    });
    nav.appendChild(inner);header.appendChild(nav);

    const drawer=document.createElement('div');
    drawer.className='category-drawer';drawer.hidden=true;
    drawer.innerHTML='<div class="wrap drawer-inner"><div class="drawer-head"><div><strong>Todas as categorias</strong><small>Encontre por necessidade, classe ou cadastro sanitário</small></div><button type="button" class="drawer-close" aria-label="Fechar categorias">×</button></div><div class="drawer-curated"></div><div class="drawer-regulatory"><strong>Categorias do catálogo</strong><div class="drawer-dynamic"><span>Carregando…</span></div></div></div>';
    header.appendChild(drawer);
    const curatedBox=qs('.drawer-curated',drawer);
    curated.forEach(([label,q])=>{
      const a=document.createElement('a');a.href=searchUrl(q);a.innerHTML=`<b>${esc(label)}</b><span>${q?'Ver produtos':'Ver todo o catálogo'}</span>`;curatedBox.appendChild(a);
    });
    const setDrawer=open=>{drawer.hidden=!open;all.setAttribute('aria-expanded',String(open));};
    all.setAttribute('aria-expanded','false');all.addEventListener('click',()=>setDrawer(drawer.hidden));
    qs('.drawer-close',drawer).addEventListener('click',()=>setDrawer(false));
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!drawer.hidden)setDrawer(false);});

    fetch(new URL('api/categories.php',location.href),{cache:'no-store'}).then(r=>r.ok?r.json():null).then(data=>{
      if(!data?.categories?.length)return;
      const box=qs('.drawer-dynamic',drawer);box.innerHTML='';
      data.categories.slice(0,24).forEach(c=>{
        const a=document.createElement('a');a.href=searchUrl(String(c.name||''));a.textContent=`${c.name} (${Number(c.total||0).toLocaleString('pt-BR')})`;box.appendChild(a);
      });
    }).catch(()=>{});

    const hero=qs('.hero');
    if(hero){
      const eyebrow=qs('.eyebrow',hero);if(eyebrow)eyebrow.textContent='Sua farmácia online';
      const h1=qs('h1',hero);if(h1)h1.textContent='Medicamentos e cuidados para o dia a dia, em um só lugar.';
      const p=qs('p',hero);if(p)p.textContent='Pesquise medicamentos por nome, princípio ativo, laboratório ou categoria. Produtos sob prescrição seguem para avaliação farmacêutica.';
      const heroForm=qs('form',hero);if(heroForm)heroForm.classList.add('hero-search');
      const service=document.createElement('div');
      service.className='service-strip';
      service.innerHTML='<div><b>⚡ Busca rápida</b><span>Mais de 32 mil registros no catálogo</span></div><div><b>📄 Envio de receita</b><span>Fluxo protegido para avaliação</span></div><div><b>🚚 Entrega ou retirada</b><span>Escolha no fechamento do pedido</span></div><div><b>💬 Atendimento</b><span>Chat e suporte farmacêutico</span></div>';
      hero.insertAdjacentElement('afterend',service);
    }

    const catalogSection=(qs('.grid')||qs('.empty'))?.closest('section');
    if(catalogSection&&!qs('.quick-categories',catalogSection)){
      const quick=document.createElement('div');quick.className='quick-categories';
      curated.slice(1,9).forEach(([label,q],idx)=>{
        const a=document.createElement('a');a.href=searchUrl(q);a.innerHTML=`<span class="quick-icon">${['💊','🌡️','🤧','🌿','❤️','🩸','🫀','♀️'][idx]||'+'}</span><b>${esc(label)}</b>`;quick.appendChild(a);
      });
      catalogSection.insertBefore(quick,catalogSection.querySelector('.section-title'));
    }

    qsa('input[name="zip"]').forEach(i=>{if(savedZip.length===8&&!i.value)i.value=savedZip;});
  };

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

      const makeLink=(label,count,q,active=false)=>{
        const u=new URL(location.href);
        if(q)u.searchParams.set('q',q);else u.searchParams.delete('q');
        u.hash='';
        const a=document.createElement('a');
        a.href=u.pathname+(u.search||'');
        a.textContent=count===null?label:`${label} (${count})`;
        if(active)a.classList.add('active');
        return a;
      };

      const current=currentSearch();
      wrap.appendChild(makeLink('Todos os produtos',Number(data.total||0),null,current===''));
      data.categories.forEach(c=>wrap.appendChild(makeLink(String(c.name||'Outros'),Number(c.total||0),String(c.name||''),current===String(c.name||''))));
      title.insertAdjacentElement('afterend',wrap);

      const counter=title.querySelector('span');
      if(counter&&Number(data.total)>0&&current==='')counter.textContent=`${Number(data.total).toLocaleString('pt-BR')} produtos cadastrados · exibindo os primeiros ${document.querySelectorAll('.card').length}`;
    }catch(_){/* catálogo continua funcional mesmo se o menu não carregar */}
  };

  enhanceStorefront();
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
