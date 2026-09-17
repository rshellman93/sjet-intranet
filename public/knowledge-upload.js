(() => {
    const form=document.getElementById('knowledge-upload');if(!form)return;
    const type=document.getElementById('knowledge-type'),input=document.getElementById('knowledge-file'),zone=document.getElementById('upload-zone'),status=document.getElementById('selected-file'),title=document.getElementById('knowledge-title');
    let dirty=false;
    function toggle(){const pdf=type.value==='pdf';document.getElementById('pdf-upload-fields').hidden=!pdf;document.getElementById('article-fields').hidden=pdf;input.disabled=!pdf;form.elements.body.disabled=pdf;form.elements.body.required=!pdf;}
    function selected(){
        const file=input.files[0];if(!file)return;
        if(!file.name.toLowerCase().endsWith('.pdf')||file.size>20*1024*1024){status.textContent='Vælg en PDF-fil på højst 20 MB.';input.value='';return;}
        status.textContent=`Valgt: ${file.name} · ${(file.size/1024/1024).toLocaleString('da-DK',{maximumFractionDigits:1})} MB`;
        if(!title.value.trim())title.value=file.name.replace(/\.pdf$/i,'').replace(/[_-]+/g,' ').slice(0,190);
        dirty=true;
    }
    type.addEventListener('change',toggle);input.addEventListener('change',selected);
    zone.addEventListener('dragover',event=>{event.preventDefault();zone.classList.add('dragging');});
    zone.addEventListener('dragleave',()=>zone.classList.remove('dragging'));
    zone.addEventListener('drop',event=>{event.preventDefault();zone.classList.remove('dragging');if(event.dataTransfer.files.length!==1){status.textContent='Upload én PDF ad gangen, så hver fil får sin egen titel og versionshistorik.';return;}input.files=event.dataTransfer.files;selected();});
    form.addEventListener('input',()=>dirty=true);
    form.addEventListener('submit',()=>{dirty=false;document.getElementById('upload-progress').hidden=false;});
    window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
    toggle();
})();
