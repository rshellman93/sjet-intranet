import React, { Component, useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Document, Page, pdfjs } from 'react-pdf';
import 'react-pdf/dist/Page/AnnotationLayer.css';
import 'react-pdf/dist/Page/TextLayer.css';

pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString();
const options={cMapUrl:'/build/pdf-assets/cmaps/',cMapPacked:true,standardFontDataUrl:'/build/pdf-assets/standard_fonts/',wasmUrl:'/build/pdf-assets/wasm/',isEvalSupported:false};

class ReaderBoundary extends Component {
    state={failed:false};
    static getDerivedStateFromError(){return {failed:true};}
    render(){return this.state.failed ? <p role="alert" className="alert error">PDF-læseren kunne ikke starte. Brug “Hent PDF” eller genindlæs siden.</p>:this.props.children;}
}

function Reader({url}){
    const [pages,setPages]=useState(0),[page,setPage]=useState(1),[jump,setJump]=useState('1'),[zoom,setZoom]=useState(1),[rotation,setRotation]=useState(0),[width,setWidth]=useState(300),[retry,setRetry]=useState(0),[failed,setFailed]=useState(false),[password,setPassword]=useState(false);
    const container=useRef(null);
    useEffect(()=>{
        const observer=new ResizeObserver(entries=>setWidth(Math.max(180,Math.min(1100,entries[0].contentRect.width-24))));
        observer.observe(container.current);return ()=>observer.disconnect();
    },[]);
    function go(number){const next=Math.max(1,Math.min(pages,number));setPage(next);setJump(String(next));}
    return <section className="pdf-reader" aria-label="PDF-læser">
        <div className="pdf-toolbar"><div className="pdf-controls"><button type="button" onClick={()=>go(page-1)} disabled={!pages||page===1} aria-label="Forrige PDF-side">←</button><form onSubmit={event=>{event.preventDefault();if(Number.isInteger(Number(jump)))go(Number(jump));}}><label>Side <input aria-label="PDF-sidenummer" type="number" min="1" max={pages||1} value={jump} onChange={e=>setJump(e.target.value)} onBlur={()=>{if(Number.isInteger(Number(jump))&&pages)go(Number(jump));}} disabled={!pages}/></label><span>af {pages||'…'}</span><button type="submit" disabled={!pages}>Gå</button></form><button type="button" onClick={()=>go(page+1)} disabled={!pages||page===pages} aria-label="Næste PDF-side">→</button></div>
        <div className="pdf-controls"><button type="button" onClick={()=>setZoom(value=>Math.max(.5,value-.25))} disabled={zoom<=.5} aria-label="Zoom ud">−</button><span aria-live="polite">{Math.round(zoom*100)} %</span><button type="button" onClick={()=>setZoom(value=>Math.min(2.5,value+.25))} disabled={zoom>=2.5} aria-label="Zoom ind">＋</button><button type="button" onClick={()=>setZoom(1)}>Tilpas bredde</button><button type="button" onClick={()=>setRotation(value=>(value+90)%360)} aria-label="Rotér PDF-side">↻</button></div></div>
        <div className="pdf-stage" ref={container} tabIndex="0" aria-label="PDF-side. Ved zoom kan du rulle sidelæns.">
            {password ? <p role="alert" className="alert error">PDF’en kræver en adgangskode. Hent filen og åbn den i din egen PDF-læser, eller bed kontoret om en læsbar version.</p> : <Document key={retry} file={url} options={options} suspense={false} onLoadSuccess={pdf=>{setPages(pdf.numPages);setFailed(false);}} onLoadError={()=>setFailed(true)} onPassword={()=>{setPassword(true);setFailed(true);}} onItemClick={({pageNumber})=>go(pageNumber)} externalLinkTarget="_blank" loading={<p role="status">Indlæser PDF…</p>} error={<p role="alert" className="alert error">PDF’en kunne ikke åbnes. Dit login kan være udløbet, eller filen kan være utilgængelig. Prøv igen eller brug “Hent PDF”.</p>}>
                <Page pageNumber={page} width={width} scale={zoom} rotate={rotation} devicePixelRatio={Math.min(window.devicePixelRatio||1,2)} renderForms={false} loading={<p role="status">Indlæser side {page}…</p>} error={<p role="alert">Siden kunne ikke vises. Prøv at hente PDF-filen.</p>} />
            </Document>}
        </div>
        <div className="pdf-footer"><span aria-live="polite">{pages ? `Side ${page} af ${pages}`:'PDF-læser'}</span>{failed && <button type="button" className="btn secondary small" onClick={()=>{setFailed(false);setPassword(false);setPages(0);setRetry(value=>value+1);}}>Prøv igen</button>}<span>Brug zoom for at læse små detaljer</span></div>
    </section>;
}

const root=document.getElementById('knowledge-reader');
if(root){createRoot(root).render(<ReaderBoundary><Reader url={root.dataset.url}/></ReaderBoundary>);}
