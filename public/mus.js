(() => {
    const form=document.getElementById('mus-form');
    if(!form)return;
    const status=document.getElementById('save-status'), error=document.getElementById('save-error');
    const sections=[...document.querySelectorAll('[data-mus-step]')], buttons=[...document.querySelectorAll('[data-step-button]')];
    const locked=form.querySelector('fieldset').disabled;
    let step=0, dirty=false, inFlight=null, timer;
    function showStep(next){
        step=Math.max(0,Math.min(3,next));
        sections.forEach((s,i)=>s.hidden=i!==step);
        buttons.forEach((b,i)=>{if(i===step)b.setAttribute('aria-current','step');else b.removeAttribute('aria-current');});
        document.getElementById('previous-step').disabled=step===0;
        document.getElementById('next-step').hidden=step===3;
    }
    buttons.forEach(b=>b.addEventListener('click',()=>showStep(Number(b.dataset.stepButton))));
    document.getElementById('previous-step').addEventListener('click',()=>showStep(step-1));
    document.getElementById('next-step').addEventListener('click',()=>showStep(step+1));
    showStep(0);
    async function persist(force=false){
        if(locked)return;
        clearTimeout(timer); if(force)dirty=true;
        if(inFlight)return inFlight;
        inFlight=(async()=>{
            while(dirty){
                dirty=false;status.textContent='Gemmer…';error.hidden=true;
                const controller=new AbortController(), timeout=setTimeout(()=>controller.abort(),20000);
                try{
                    const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});
                    const data=await response.json();
                    if(!response.ok)throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Gemningen kunne ikke gennemføres.');
                    form.elements.revision.value=data.revision;
                    form.elements.preparation_revision.value=data.preparation_revision;
                    document.querySelectorAll('[data-sync-mus] input[name=revision]').forEach(e=>e.value=data.revision);
                    status.textContent=dirty ? 'Gemmer nye ændringer…' : 'Gemt kl. '+data.saved_at;
                }catch(e){dirty=true;status.textContent='Ikke gemt';error.textContent=(e.name==='AbortError' ? 'Serveren svarede ikke i tide.' : e.message)+' Dine indtastninger er stadig på skærmen. Prøv Gem nu, eller hent en lokal kopi.';error.hidden=false;throw e;}
                finally{clearTimeout(timeout);}
            }
        })().finally(()=>inFlight=null);
        return inFlight;
    }
    function preview(){
        const data=new FormData(form), target=document.getElementById('draft-preview'); target.replaceChildren();
        const title=document.createElement('strong');title.textContent='Forhåndsvisning af overførsel til KLS';target.append(title);
        let count=0;
        for(let i=0;i<3;i++){
            const field=k=>data.get(`shared[goals][${i}][${k}]`) || '';
            if(!field('result') || !['competency','course','both'].includes(field('transfer')))continue;
            count++;
            const selected=k=>form.elements.namedItem(`shared[goals][${i}][${k}]`)?.selectedOptions?.[0]?.textContent || '';
            const p=document.createElement('p');
            p.textContent=`Mål ${i+1}: ${field('result')}\nFaglig beskrivelse: ${field('practice') || 'Ikke angivet'}\nFrist: ${field('deadline') || 'Mangler'}`;
            if(['competency','both'].includes(field('transfer')))p.textContent+=`\nKompetence: ${selected('competency_id')} · Ønsket niveau: ${selected('desired_level')}`;
            if(['course','both'].includes(field('transfer')))p.textContent+=`\nKursusbehov: ${field('course_topic') || 'Mangler'} · ${selected('link_course_id')}`;
            target.append(p);
        }
        const note=document.createElement('p');note.textContent=count ? 'Kun de viste faglige oplysninger overføres, når begge har bekræftet samme version. Puls og samtalenoter følger ikke med.' : 'Ingen overførsel valgt. Aftalerne forbliver i MUS.';target.append(note);
    }
    function changed(){if(locked)return;dirty=true;status.textContent='Ikke gemt endnu';preview();clearTimeout(timer);timer=setTimeout(()=>persist().catch(()=>{}),700);}
    form.addEventListener('input',changed);form.addEventListener('change',changed);
    form.addEventListener('submit',event=>{event.preventDefault();persist(true).catch(()=>{});});
    document.getElementById('save-retry').addEventListener('click',()=>persist(true).catch(()=>{}));
    document.getElementById('save-copy').addEventListener('click',()=>{
        const blob=new Blob([JSON.stringify({type:'Fortrolig lokal MUS-kopi',saved_at:new Date().toISOString(),fields:Object.fromEntries([...new FormData(form)].filter(([k])=>k!=='_token'))},null,2)],{type:'application/json'});
        const url=URL.createObjectURL(blob), link=document.createElement('a');link.href=url;link.download='mus-lokal-kopi.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    document.querySelectorAll('[data-sync-mus]').forEach(action=>action.addEventListener('submit',async event=>{
        event.preventDefault();
        try{await persist(true);action.submit();}
        catch{action.querySelectorAll('button').forEach(b=>{b.disabled=false;if(b.dataset.originalText)b.textContent=b.dataset.originalText;});error.scrollIntoView({block:'center'});}
    }));
    document.getElementById('suggest-dates').addEventListener('click',()=>{
        const value=form.elements.namedItem('shared[held_on]').value;if(!value){alert('Angiv først den faktiske samtaledato.');return;}
        const date=new Date(value+'T12:00:00');
        for(const [field,days] of [['checkin_on',30],['followup_on',90],['next_mus_on',365]]){
            const input=form.elements.namedItem(`shared[${field}]`);if(input.value)continue;
            const next=new Date(date);next.setDate(next.getDate()+days);input.value=`${next.getFullYear()}-${String(next.getMonth()+1).padStart(2,'0')}-${String(next.getDate()).padStart(2,'0')}`;
        }changed();
    });
    window.addEventListener('beforeunload',event=>{if(dirty || inFlight){event.preventDefault();event.returnValue='';}});
    if(locked){document.getElementById('save-retry').disabled=true;form.querySelector('button[type=submit]').disabled=true;status.textContent='Arkiveret · skrivebeskyttet';}
    preview();
})();
