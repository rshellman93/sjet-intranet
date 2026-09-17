import { Timeline } from 'vis-timeline/standalone';
import 'vis-timeline/styles/vis-timeline-graph2d.min.css';

const root = document.getElementById('staff-calendar');
if (root) {
 const data = JSON.parse(root.dataset.calendar);
 const date = text => { const [y,m,d] = text.split('-').map(Number); return new Date(y,m-1,d); };
 const textNode = text => { const span = document.createElement('span'); span.textContent = text; return span; };
 const day = 24 * 60 * 60 * 1000;
 const fullStart = date(data.start);
 const fullEnd = date(data.end);
 const items = data.events.map(event => ({...event, start: date(event.start), end: date(event.end), type:'range', content: textNode(event.content)}));
 const groups = data.people.map((person,index) => ({id:person.id, content:textNode(person.name), order:index}));
 try {
  const timeline = new Timeline(root, items, groups, {
   start: fullStart, end: fullEnd, min:fullStart, max:fullEnd,
   locale:'da', locales:{da:{current:'nu',time:'tid',deleteSelected:'Slet valgte'}}, groupOrder:'order', editable:false,
   zoomable:true, moveable:true, zoomKey:'ctrlKey', zoomMin:3 * day, zoomMax:fullEnd.valueOf() - fullStart.valueOf(),
   orientation:'top', stack:true, margin:{item:8,axis:10}, showCurrentTime:true,
   timeAxis:{scale:'day',step:1}, horizontalScroll:true,
   format:{minorLabels: value => String(new Date(value.valueOf()).getDate()),majorLabels: value => new Intl.DateTimeFormat('da-DK',{month:'long',year:'numeric'}).format(new Date(value.valueOf()))}
  });

  const fullMin = fullStart.valueOf();
  const fullMax = fullEnd.valueOf();
  const fullSpan = fullMax - fullMin;
  const setWindow = (requestedStart, requestedEnd) => {
   const span = Math.min(Math.max(requestedEnd - requestedStart, 3 * day), fullSpan);
   const start = Math.max(fullMin, Math.min(requestedStart, fullMax - span));
   timeline.setWindow(new Date(start), new Date(start + span), {animation:true});
  };
  const move = direction => {
   const window = timeline.getWindow();
   const span = window.end.valueOf() - window.start.valueOf();
   const distance = span * 0.4 * direction;
   setWindow(window.start.valueOf() + distance, window.end.valueOf() + distance);
  };
  const zoom = factor => {
   const window = timeline.getWindow();
   const center = (window.start.valueOf() + window.end.valueOf()) / 2;
   const span = (window.end.valueOf() - window.start.valueOf()) * factor;
   setWindow(center - span / 2, center + span / 2);
  };

  document.querySelectorAll('[data-calendar-action]').forEach(button => {
   button.addEventListener('click', () => {
    const action = button.dataset.calendarAction;
    if (action === 'previous') move(-1);
    if (action === 'next') move(1);
    if (action === 'zoom-in') zoom(0.65);
    if (action === 'zoom-out') zoom(1 / 0.65);
    if (action === 'reset') setWindow(fullMin, fullMax);
   });
  });
  timeline.on('select', ({items}) => {
   if (!items.length) return;
   const card = document.getElementById('calendar-entry-'+items[0]);
   if (card) { card.scrollIntoView({behavior:'smooth',block:'center'}); card.focus({preventScroll:true}); }
  });
 } catch (error) {
  root.textContent = 'Kalendergrafikken kunne ikke vises. Se alle registreringer i listen nedenfor.';
 }
}
