document.querySelectorAll('form').forEach(form=>{
    form.addEventListener('submit',()=>{
        if(form.hasAttribute('data-allow-repeat'))return;
        const button=form.querySelector('button[type=submit]');
        if(button){setTimeout(()=>{button.disabled=true;button.dataset.originalText=button.textContent;button.textContent='Arbejder…';},0);}
    });
});
window.addEventListener('pageshow',()=>document.querySelectorAll('button[data-original-text]').forEach(b=>{b.disabled=false;b.textContent=b.dataset.originalText;}));
document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
