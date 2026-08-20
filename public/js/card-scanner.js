const APP_VERSION = "8.43";
const SCANNER_VERSION = APP_VERSION;
const SCANNER_BUILD = "Login Name Warning";
const SCANNER_BUILD_ID = `v${APP_VERSION}-login-name-warning`;

function scannerVersionLine() {
  return `SCANNER | version=${SCANNER_VERSION} | build="${SCANNER_BUILD}" | id=${SCANNER_BUILD_ID}`;
}

window.CLASH_CARD_SCANNER_VERSION = {
  version: SCANNER_VERSION,
  build: SCANNER_BUILD,
  id: SCANNER_BUILD_ID
};

console.info(scannerVersionLine());

(() => {
"use strict";

const catalog = Array.isArray(window.CLASH_CARDS) ? window.CLASH_CARDS : [];
const byKey = new Map(catalog.map(c => [`${c.category}|${c.name}`, c]));

const EVENT_ORDER = [
 ["Elixir","Barbarian"],["Elixir","Archer"],["Elixir","Giant"],["Elixir","Goblin"],
 ["Elixir","Wall Breaker"],["Elixir","Balloon"],["Elixir","Wizard"],["Elixir","Healer"],
 ["Elixir","Dragon"],["Elixir","P.E.K.K.A"],["Elixir","Baby Dragon"],["Elixir","Miner"],
 ["Elixir","Electro Dragon"],["Elixir","Yeti"],["Elixir","Dragon Rider"],["Elixir","Electro Titan"],
 ["Elixir","Root Rider"],["Elixir","Thrower"],["Elixir","Meteor Golem"],

 ["Dark Elixir","Minion"],["Dark Elixir","Hog Rider"],["Dark Elixir","Valkyrie"],
 ["Dark Elixir","Golem"],["Dark Elixir","Witch"],["Dark Elixir","Lava Hound"],
 ["Dark Elixir","Bowler"],["Dark Elixir","Ice Golem"],["Dark Elixir","Headhunter"],
 ["Dark Elixir","Apprentice Warden"],["Dark Elixir","Druid"],["Dark Elixir","Furnace"],
 ["Dark Elixir","Ruin Witch"],

 ["Builder Base","Raged Barbarian"],["Builder Base","Sneaky Archer"],["Builder Base","Boxer Giant"],
 ["Builder Base","Beta Minion"],["Builder Base","Bomber"],["Builder Base","Baby Dragon"],
 ["Builder Base","Cannon Cart"],["Builder Base","Night Witch"],["Builder Base","Drop Ship"],
 ["Builder Base","Power P.E.K.K.A"],["Builder Base","Hog Glider"],

 ["Super","Super Barbarian"],["Super","Super Archer"],["Super","Super Giant"],
 ["Super","Sneaky Goblin"],["Super","Super Wall Breaker"],["Super","Rocket Balloon"],
 ["Super","Super Wizard"],["Super","Super Dragon"],["Super","Inferno Dragon"],
 ["Super","Super Miner"],["Super","Super Yeti"],["Super","Super Minion"],
 ["Super","Super Hog Rider"],["Super","Super Valkyrie"],["Super","Super Witch"],
 ["Super","Ice Hound"],["Super","Super Bowler"]
];

const PAGE_SIGNATURES = [
 "EEEEEEEEEEEE",
 "EEEEEEEDDDDD",
 "DDDDDDDDBBBB",
 "BBBBBBBSSSSS",
 "SSSSSSSSSSSS"
];

const input = document.getElementById("screenshots");
const analyze = document.getElementById("analyzeScreenshots");
const progressWrap = document.getElementById("ocrProgressWrap");
const progress = document.getElementById("ocrProgress");
const status = document.getElementById("ocrStatus");
const review = document.getElementById("detectedReview");
const body = document.getElementById("detectedBody");
const save = document.getElementById("saveDetectedButton");
const rawWrap = document.getElementById("rawOcrWrap");
const raw = document.getElementById("rawOcrText");
const learningConsent = document.getElementById("learningConsent");
const exportLearning = document.getElementById("exportLearning");
const clearLearning = document.getElementById("clearLearning");
const learningStatus = document.getElementById("learningStatus");
const detectedUsernameField = document.getElementById("detectedUsername");
const saveTargetName = document.getElementById("saveTargetName");
const teachSelected = document.getElementById("teachSelectedExamples");
const scanCompleteness = document.getElementById("scanCompleteness");
const saveDetectedButton = document.getElementById("saveDetectedButton");
const usernameReviewWarning = document.getElementById("usernameReviewWarning");
let usernameNeedsManualReview = false;

const LEARNING_STORAGE_KEY = "clashCardsLearnedGlyphs.v1";
const MAX_LEARNED_PER_QTY = 40;

let currentLearningRows = new Map();

function normalizeDetectedUsername(value){
  let s=String(value||"").trim();

  // Remove common OCR noise before/after the actual Clash player name.
  s=s.replace(/^[0-9]+\s+/, "");
  s=s.replace(/\s+[_|~\-]+\s*[A-Za-z0-9]*\s*$/g, "");
  s=s.replace(/\s{2,}/g, " ").trim();

  // If OCR produced a compact username followed by obvious spaced junk,
  // keep the leading username token.
  const m=s.match(/^([A-Za-z0-9][A-Za-z0-9._-]{2,31})(?:\s+[_|~\-A-Za-z0-9]+)+$/);
  if(m) s=m[1];

  // Preserve legitimate internal punctuation, but strip leading/trailing junk.
  s=s.replace(/^[^A-Za-z0-9]+|[^A-Za-z0-9._-]+$/g, "");

  return s;
}

function syncSaveTargetName(){
  if(!saveTargetName || !detectedUsernameField) return;
  const value=detectedUsernameField.value.trim();
  saveTargetName.textContent=value || "(player name required)";
}


if (!input || !analyze) return;

let badgeTemplates = null;
analyze.addEventListener("click", run);

if (exportLearning) {
  exportLearning.addEventListener("click", exportLearnedGlyphs);
}
if (clearLearning) {
  clearLearning.addEventListener("click", () => {
    if (!confirm("Clear all badge examples learned in this browser?")) return;
    localStorage.removeItem(LEARNING_STORAGE_KEY);
    updateLearningStatus();
if(detectedUsernameField){
  detectedUsernameField.addEventListener("input",()=>{
    syncSaveTargetName();
    if(usernameNeedsManualReview && detectedUsernameField.value.trim().length>=3){
      usernameNeedsManualReview=false;
      detectedUsernameField.classList.remove("username-review-required");
      if(usernameReviewWarning) usernameReviewWarning.hidden=true;
      if(saveDetectedButton?.dataset?.hasReviewableScan==="1"){
        saveDetectedButton.disabled=false;
      }
    }
  });
}
syncSaveTargetName();

  });
}
if (review) {
  review.addEventListener("submit", (event) => {
    if (saveDetectedButton?.disabled) {
      event.preventDefault();
      alert("There is no scanned inventory to save yet.");
      return;
    }

    if (saveDetectedButton?.dataset?.partialScan === "1") {
      const ok=confirm(
        "This is a partial scan. Cards from unread pages are filled from the player's previous saved inventory, or 0 when no previous value exists. Save after reviewing those rows?"
      );
      if(!ok) event.preventDefault();
    }
  });
}

if (teachSelected) {
  teachSelected.addEventListener("click", () => {
    learnFromReviewedRows();
  });
}

if(detectedUsernameField){
  detectedUsernameField.addEventListener("change",async()=>{
    if(saveDetectedButton?.dataset?.partialScan!=="1") return;

    const previous=await fetchPreviousInventory(detectedUsernameField.value);
    const quantities=previous.quantities||{};

    for(const inputEl of body.querySelectorAll("tr.scan-fallback-row input[name^='detected[']")){
      const m=inputEl.name.match(/^detected\[(\d+)\]$/);
      if(!m) continue;

      const cardId=m[1];
      const hasPrevious=Object.prototype.hasOwnProperty.call(quantities,cardId);
      inputEl.value=hasPrevious ? Math.max(0,Number(quantities[cardId])||0) : 0;

      const badge=inputEl.closest("tr")?.querySelector(".confidence");
      if(badge){
        badge.className=`confidence confidence-${hasPrevious?"previous":"manual"}`;
        badge.textContent=hasPrevious?"Previous":"Enter";
      }
    }
  });
}
updateLearningStatus();

async function run() {
  const files = [...(input.files || [])];
  if (!files.length) return alert("Choose one or more screenshots.");

  analyze.disabled = true;
  review.hidden = true;
  rawWrap.hidden = true;
  body.innerHTML = "";
  raw.textContent = "";
  setStatus("Loading scanner…", 0);

  let worker = null;

  try {
    badgeTemplates = await loadBadgeTemplates();

    if (window.Tesseract) {
      worker = await Tesseract.createWorker("eng");
    }

    const pages = new Map();
    const geometries = new Map();
    const allImages = [];
    const debug = [];
    currentLearningRows = new Map();

    if(saveDetectedButton){
      saveDetectedButton.disabled=true;
      saveDetectedButton.dataset.scanComplete="0";
      saveDetectedButton.dataset.partialScan="0";
      saveDetectedButton.dataset.hasReviewableScan="0";
      if(usernameReviewWarning){
        usernameReviewWarning.hidden=true;
        usernameReviewWarning.innerHTML="";
      }
    }
    if(scanCompleteness){
      scanCompleteness.textContent="Analyzing screenshots…";
      scanCompleteness.className="scan-completeness";
    }
    if(teachSelected){
      teachSelected.disabled=true;
    }
    debug.push(scannerVersionLine());

    const candidates=[];
    const gridFailures=[];

    for (let i=0; i<files.length; i++) {
      const img = await loadImage(files[i]);
      allImages.push(img);

      const gridResult = detectGridGeometry(img);
      const geometry = gridResult.geometry;

      if (gridResult.debug?.length) {
        for (const line of gridResult.debug) {
          debug.push(`${files[i].name}: ${line}`);
        }
      }

      if (!geometry) {
        debug.push(`${files[i].name}: could not locate card grid after primary + fallback`);
        gridFailures.push({file:files[i],img});
        continue;
      }

      const pageResult = scoreAllPages(img, geometry);

      candidates.push({
        file: files[i],
        img,
        geometry,
        pageResult
      });

      debug.push(
        `${files[i].name}: candidate | signature=${pageResult.signature} | scores=${formatPageScores(pageResult.pageScores)} | grid=${geometrySummary(geometry)}`
      );

      setStatus(
        `Analyzed ${files[i].name}`,
        8 + Math.round(14*(i+1)/files.length)
      );
    }

    // V8.32: screenshots uploaded together normally come from one capture session,
    // so their card panel geometry is effectively identical. If one page loses
    // several colored frame components, retry it using geometry from a successful
    // same-size screenshot. Page scoring still verifies the recovered page.
    if(gridFailures.length && candidates.length){
      for(const failed of gridFailures){
        const donors=candidates
          .filter(c=>c.img.naturalWidth===failed.img.naturalWidth && c.img.naturalHeight===failed.img.naturalHeight)
          .sort((a,b)=>geometryDonorQuality(b.geometry)-geometryDonorQuality(a.geometry));

        if(!donors.length){
          debug.push(`${failed.file.name}: SET_GEOMETRY_RECOVERY | result=failed | reason=no-same-size-donor`);
          continue;
        }

        const donor=donors[0];
        const geometry=cloneGeometryForSetRecovery(donor.geometry);
        const pageResult=scoreAllPages(failed.img,geometry);
        candidates.push({file:failed.file,img:failed.img,geometry,pageResult});
        debug.push(`${failed.file.name}: SET_GEOMETRY_RECOVERY | result=accepted | donor=${donor.file.name} | signature=${pageResult.signature} | scores=${formatPageScores(pageResult.pageScores)} | grid=${geometrySummary(geometry)}`);
      }
    }

    const assignment = assignPagesGlobally(candidates);

    for (const item of assignment.items) {
      const page=item.page;
      pages.set(page,item.candidate.img);
      geometries.set(page,item.candidate.geometry);

      debug.push(
        `${item.candidate.file.name}: page ${page+1} | assignedScore=${item.score.toFixed(2)} | signature=${item.candidate.pageResult.signature} | grid=${geometrySummary(item.candidate.geometry)}`
      );
    }

    if (assignment.unassigned.length) {
      for (const item of assignment.unassigned) {
        debug.push(
          `${item.file.name}: unassigned after whole-set optimization | signature=${item.pageResult.signature} | scores=${formatPageScores(item.pageResult.pageScores)}`
        );
      }
    }

    debug.push(
      `PAGE_ASSIGNMENT | assigned=${assignment.items.map(x=>`P${x.page+1}:${x.candidate.file.name}`).join(" | ")} | total=${assignment.totalScore.toFixed(2)}`
    );

    const detectedPageCount=pages.size;
    const completeScan=detectedPageCount===5;
    const reviewableScan=detectedPageCount>0;

    debug.push(
      `SCAN_COMPLETE | pages=${detectedPageCount}/5 | saveAllowed=${reviewableScan} | mode=${completeScan?"complete":"partial-review"}`
    );

    if(scanCompleteness){
      if(completeScan){
        scanCompleteness.textContent="All 5 pages detected. Inventory can be saved.";
        scanCompleteness.className="scan-completeness scan-complete";
      }else if(reviewableScan){
        scanCompleteness.textContent=`${detectedPageCount}/5 pages detected. Missing-page cards will be filled from previous saved values, or 0 if no previous value exists. Review the highlighted rows before saving.`;
        scanCompleteness.className="scan-completeness scan-incomplete";
      }else{
        scanCompleteness.textContent="No card pages were detected. Nothing can be saved yet.";
        scanCompleteness.className="scan-completeness scan-incomplete";
      }
    }

    if(saveDetectedButton){
      saveDetectedButton.dataset.hasReviewableScan=reviewableScan?"1":"0";
      saveDetectedButton.disabled=!reviewableScan;
      saveDetectedButton.dataset.scanComplete=completeScan?"1":"0";
      saveDetectedButton.dataset.partialScan=completeScan?"0":"1";
    }

    // V8.39: username OCR is a warning only. Check every uploaded file
    // against the logged-in player. Matching ignores case and periods.
    const loggedInPlayerName=String(document.body?.dataset?.loggedInPlayer||"").trim();
    const normalizedLoggedIn=normalizeUsernameForLoginCheck(loggedInPlayerName);
    const usernameChecks=[];

    if(worker && allImages.length){
      setStatus("Checking player names…",28);

      for(let i=0;i<allImages.length;i++){
        try{
          const usernameResult=await readUsernameForWarning(worker,allImages[i]);
          const detected=normalizeDetectedUsername(usernameResult.cleaned);
          const normalizedDetected=normalizeUsernameForLoginCheck(detected);
          const matches=
            normalizedLoggedIn!=="" &&
            normalizedDetected!=="" &&
            normalizedLoggedIn===normalizedDetected;

          usernameChecks.push({
            file:files[i]?.name||`Screenshot ${i+1}`,
            detected,
            confidence:usernameResult.confidence,
            matches
          });

          debug.push(
            `USERNAME_CHECK | file=${JSON.stringify(files[i]?.name||`Screenshot ${i+1}`)} | loggedIn=${JSON.stringify(loggedInPlayerName)} | ocr=${JSON.stringify(detected)} | normalizedLoggedIn=${JSON.stringify(normalizedLoggedIn)} | normalizedOcr=${JSON.stringify(normalizedDetected)} | match=${matches?"yes":"no"} | confidence=${usernameResult.confidence.toFixed(1)} | source=${usernameResult.source} | variant=${usernameResult.variant}`
          );
        }catch(e){
          usernameChecks.push({
            file:files[i]?.name||`Screenshot ${i+1}`,
            detected:"",
            confidence:0,
            matches:null
          });
          debug.push(
            `USERNAME_CHECK | file=${JSON.stringify(files[i]?.name||`Screenshot ${i+1}`)} | result=unreadable | error=${JSON.stringify(e?.message||String(e))}`
          );
        }
      }
    }

    renderUsernameLoginWarning(usernameChecks,loggedInPlayerName);

    // Partial-scan fallback must also use the logged-in player, not OCR.
    let previousInventory={found:false,display_name:null,quantities:{}};
    if(loggedInPlayerName){
      previousInventory=await fetchPreviousInventory(loggedInPlayerName);
      debug.push(
        `PREVIOUS_INVENTORY | player=${JSON.stringify(loggedInPlayerName)} | found=${previousInventory.found} | savedRows=${Object.keys(previousInventory.quantities||{}).length}`
      );
    }

    const results = [];
    const sortedPages = [...pages.entries()].sort((a,b)=>a[0]-b[0]);
    let done=0;
    const totalSlots=sortedPages.length*12;

    for (const [page,img] of sortedPages) {
      const geometry=geometries.get(page);

      for (let slot=0; slot<12; slot++) {
        const orderIndex=page*12+slot;
        if(orderIndex>=EVENT_ORDER.length) continue;

        const [category,name]=EVENT_ORDER[orderIndex];
        const dbCard=byKey.get(`${category}|${name}`);
        if(!dbCard){
          debug.push(`Missing DB card: ${category} / ${name}`);
          continue;
        }

        const box=geometry.boxes[slot];
        const state=analyzeCardPixels(img,box);
        const badge=state.grayscale ? null : extractBadge(img,box);

        let qty=0;
        let confidence="high";
        let detail="none";
        let glyphDebug="none";

        if(state.grayscale){
          qty=0;
        } else if(!badge){
          qty=1;
        } else {
          const match=matchBadgeCanvas(badge.normalizedCanvas,badgeTemplates);
          const known=acceptTemplateMatch(match);
          glyphDebug=match.glyph ? `${match.glyph.w}x${match.glyph.h}/${match.glyph.components}@${match.glyph.badgeInterior}` : "none";

          // V8.33: OCR the complete yellow badge first. The x + digit(s) have
          // much more context here than in the extracted binary glyph, and this
          // is especially important for x3/x4/x5 at smaller screenshot sizes.
          let fullBadgeOcr=null;
          if(worker){
            fullBadgeOcr=await readBadgeQuantityCanvas(worker,badge.ocrCanvas,32);
          }

          if(fullBadgeOcr?.qty!==null){
            qty=fullBadgeOcr.qty;
            confidence=fullBadgeOcr.confidence;
            detail=`badge-ocr:${fullBadgeOcr.raw}`;
          } else if(known.accepted){
            qty=known.qty;
            confidence=known.confidence;
            detail=`glyph-${known.detail}`;
          } else if(worker){
            const glyphForOcr=match.glyph ? upscaleGlyphForOcr(match.glyph.canvas) : badge.ocrCanvas;
            const ocr=await readBadgeQuantityCanvas(worker,glyphForOcr,50);
            if(ocr.qty!==null){
              qty=ocr.qty;
              confidence=ocr.confidence;
              detail=`glyph-ocr:${ocr.raw}`;
            } else {
              qty=2;
              confidence="low";
              detail=`unread-badge | ${known.detail} | badgeOcr=${JSON.stringify(fullBadgeOcr?.attempts||[])} | glyphOcr=${JSON.stringify(ocr.attempts)}`;
            }
          } else {
            qty=2;
            confidence="low";
            detail=`unread-badge | ${known.detail}`;
          }
        }

        const learningId=`${page}-${slot}-${dbCard.id}`;
        let glyphDataUrl=null;

        if(badge){
          const learningMatch=matchBadgeCanvas(badge.normalizedCanvas,badgeTemplates);
          if(learningMatch.glyph){
            glyphDataUrl=learningMatch.glyph.canvas.toDataURL("image/png");
            currentLearningRows.set(learningId,{
              id:learningId,
              card_id:Number(dbCard.id),
              name,
              category,
              page:page+1,
              slot:slot+1,
              predicted_qty:qty,
              confidence,
              glyph_data_url:glyphDataUrl,
              glyph_w:learningMatch.glyph.w,
              glyph_h:learningMatch.glyph.h,
              glyph_components:learningMatch.glyph.components
            });
          }
        }

        results.push({
          card_id:Number(dbCard.id),
          name,category,owned_qty:qty,confidence,
          learning_id:learningId,
          learnable:!!glyphDataUrl
        });

        debug.push(
          `DETECT | page=${page+1} | slot=${slot+1} | ${category} | ${name} | qty=${qty} | confidence=${confidence} | grayscale=${state.grayscale} | badge=${!!badge} | badgeBox=${badge ? formatBadgeBox(badge) : "none"} | badgePos=${badge ? `${badge.relX.toFixed(2)},${badge.relY.toFixed(2)}` : "none"} | badgeSize=${badge ? `${badge.relW.toFixed(2)}x${badge.relH.toFixed(2)}` : "none"} | badgeAspect=${badge ? badge.aspect.toFixed(2) : "0.00"} | badgeFill=${badge ? badge.fill.toFixed(3) : "0.000"} | badgeYellow=${badge ? badge.yellowRatio.toFixed(3) : "0.000"} | badgeDark=${badge ? badge.darkRatio.toFixed(3) : "0.000"} | glyph=${glyphDebug} | detail=${detail}`
        );

        done++;
        setStatus(
          `Reading cards… ${done}/${totalSlots}`,
          30 + Math.round(65*done/Math.max(totalSlots,1))
        );
      }
    }

    if(!completeScan){
      addMissingPageRows(results,pages,previousInventory,debug);
    }

    render(results);

    debug.push("");
    debug.push("===== FULL DETECTED COUNTS =====");
    for(const r of results){
      debug.push(`${r.category} | ${r.name} | ${r.owned_qty} | ${r.confidence}`);
    }
    debug.push("===== END FULL DETECTED COUNTS =====");

    raw.textContent=debug.join("\n");
    rawWrap.hidden=false;

    if(pages.size<5){
      setStatus(`Finished ${pages.size} page(s). Upload all 5 screenshots to populate all 60 cards.`,100);
    } else {
      setStatus(`Finished. Read ${results.length} cards. Review quantities before saving.`,100);
    }
  } catch(e){
    console.error(e);
    setStatus("Scanner failed: "+(e?.message||String(e)),0);
  } finally {
    if(worker) await worker.terminate();
    analyze.disabled=false;
  }
}

/* ---------------- Dynamic grid geometry ---------------- */


function geometryDonorQuality(g){
  if(!g||!Array.isArray(g.boxes)||g.boxes.length!==12) return -1;
  const widths=g.boxes.map(b=>b.w);
  const heights=g.boxes.map(b=>b.h);
  const wm=median(widths), hm=median(heights);
  const spread=widths.reduce((s,v)=>s+Math.abs(v-wm),0)+heights.reduce((s,v)=>s+Math.abs(v-hm),0);
  return 1000-spread;
}

function cloneGeometryForSetRecovery(g){
  return {
    scale:g.scale,
    panelLeft:g.panelLeft,
    panelRight:g.panelRight,
    row1:[...g.row1],
    row2:[...g.row2],
    boxes:g.boxes.map(b=>({...b})),
    gridMethod:"set-recovery"
  };
}

function detectGridGeometry(img){
  const primary=detectGridGeometryPrimary(img);

  if(primary.geometry){
    return {
      geometry:primary.geometry,
      debug:[`GRID_PRIMARY | result=accepted | ${primary.summary}`]
    };
  }

  const fallback=detectGridGeometryFallback(img);

  if(fallback.geometry){
    return {
      geometry:fallback.geometry,
      debug:[
        `GRID_PRIMARY | result=failed | ${primary.summary}`,
        `GRID_FALLBACK | result=accepted | ${fallback.summary}`
      ]
    };
  }

  return {
    geometry:null,
    debug:[
      `GRID_PRIMARY | result=failed | ${primary.summary}`,
      `GRID_FALLBACK | result=failed | ${fallback.summary}`
    ]
  };
}

function detectGridGeometryPrimary(img){
  const maxW=1100;
  const scale=Math.min(1,maxW/img.naturalWidth);
  const w=Math.max(1,Math.round(img.naturalWidth*scale));
  const h=Math.max(1,Math.round(img.naturalHeight*scale));

  const c=document.createElement("canvas");
  c.width=w;c.height=h;
  const ctx=c.getContext("2d",{willReadFrequently:true});
  ctx.drawImage(img,0,0,w,h);
  const data=ctx.getImageData(0,0,w,h).data;

  const mask=new Uint8Array(w*h);
  for(let y=Math.floor(h*.28);y<Math.floor(h*.86);y++){
    for(let x=0;x<w;x++){
      const i=(y*w+x)*4;
      if(isCategoryBorderRgb(data[i],data[i+1],data[i+2])) mask[y*w+x]=1;
    }
  }

  const closed=closeBinaryMask(mask,w,h,2,2,2);
  const components=findBinaryComponents(closed,w,h);

  let boxes=components.map(comp=>({
    x:comp.minX,y:comp.minY,
    w:comp.maxX-comp.minX+1,
    h:comp.maxY-comp.minY+1,
    area:comp.count
  })).filter(b=>
    b.w>w*.040 && b.w<w*.130 &&
    b.h>h*.100 && b.h<h*.245 &&
    b.area>Math.max(70,w*h*.00035)
  );

  const rawBoxCount=boxes.length;

  // V8.26: if exactly one card frame is lost, reconstruct it from the
  // opposite row's six-column geometry instead of rejecting the page.
  let elevenBoxRecovery = null;
  if(boxes.length===11){
    elevenBoxRecovery=recoverMissingBoxFromEleven(boxes,w,h);
    if(elevenBoxRecovery){
      boxes=elevenBoxRecovery.boxes;
    }
  }

  if(boxes.length<12){
    return {
      geometry:null,
      summary:`components=${components.length} | candidateBoxes=${rawBoxCount} | reason=need-at-least-12-boxes`
    };
  }

  let clustered=clusterBoxesIntoRows(boxes,h*.055)
    .filter(row=>row.length>=6)
    .sort((a,b)=>rowMeanY(a)-rowMeanY(b));

  let noisyRowRecovery=null;
  if(clustered.length<2 && rawBoxCount>=11){
    noisyRowRecovery=recoverNoisySecondRow(boxes,w,h);
    if(noisyRowRecovery){
      boxes=noisyRowRecovery.boxes;
      clustered=clusterBoxesIntoRows(boxes,h*.055)
        .filter(row=>row.length>=6)
        .sort((a,b)=>rowMeanY(a)-rowMeanY(b));
    }
  }

  if(clustered.length<2){
    return {
      geometry:null,
      summary:`components=${components.length} | candidateBoxes=${rawBoxCount} | usableRows=${clustered.length} | reason=need-two-rows${noisyRowRecovery?"-after-repair":""}`
    };
  }

  const rows=clustered.slice(0,2);
  const rowCounts=rows.map(r=>r.length);
  const finalBoxes=[];

  let sevenSixRecovery=null;
  let repairedRows=rows;

  if(
    (rowCounts[0]===6 && rowCounts[1]===7) ||
    (rowCounts[0]===7 && rowCounts[1]===6)
  ){
    const referenceIndex=rowCounts[0]===6 ? 0 : 1;
    const noisyIndex=referenceIndex===0 ? 1 : 0;
    const reference=chooseRegularSixBoxes(rows[referenceIndex],w);
    const repairedNoisy=reference
      ? chooseSixByReferenceColumns(rows[noisyIndex],reference,w)
      : null;

    if(reference && repairedNoisy){
      repairedRows=[...rows];
      repairedRows[referenceIndex]=reference;
      repairedRows[noisyIndex]=repairedNoisy;
      sevenSixRecovery={noisyRow:noisyIndex+1};
    }
  }

  for(let r=0;r<repairedRows.length;r++){
    const selected=chooseRegularSixBoxes(repairedRows[r],w);

    if(!selected){
      return {
        geometry:null,
        summary:`components=${components.length} | candidateBoxes=${rawBoxCount} | rowCounts=${rowCounts.join("+")} | reason=row-${r+1}-not-six-regular-cards`
      };
    }

    selected.sort((a,b)=>a.x-b.x);

    for(const b of selected){
      const px=Math.max(1,b.w*.015);
      const py=Math.max(1,b.h*.010);
      finalBoxes.push({
        x:Math.max(0,(b.x-px)/scale),
        y:Math.max(0,(b.y-py)/scale),
        w:(b.w+2*px)/scale,
        h:(b.h+2*py)/scale
      });
    }
  }

  if(finalBoxes.length!==12){
    return {
      geometry:null,
      summary:`components=${components.length} | candidateBoxes=${rawBoxCount} | rowCounts=${rowCounts.join("+")} | finalBoxes=${finalBoxes.length} | reason=not-12-final-boxes`
    };
  }

  return {
    geometry:{
      scale,
      panelLeft:Math.min(...finalBoxes.map(b=>b.x)),
      panelRight:Math.max(...finalBoxes.map(b=>b.x+b.w)),
      row1:[
        Math.min(...finalBoxes.slice(0,6).map(b=>b.y)),
        Math.max(...finalBoxes.slice(0,6).map(b=>b.y+b.h))
      ],
      row2:[
        Math.min(...finalBoxes.slice(6,12).map(b=>b.y)),
        Math.max(...finalBoxes.slice(6,12).map(b=>b.y+b.h))
      ],
      boxes:finalBoxes,
      gridMethod:"primary"
    },
    summary:`components=${components.length} | candidateBoxes=${rawBoxCount} | rowCounts=${rowCounts.join("+")} | boxes=12${elevenBoxRecovery ? ` | recoveredMissingColumn=${elevenBoxRecovery.missingColumn+1}` : ""}${noisyRowRecovery ? ` | noisyRowRepair=column${noisyRowRecovery.missingColumn+1}` : ""}${sevenSixRecovery ? ` | sevenSixRepair=row${sevenSixRecovery.noisyRow}` : ""}`
  };
}

function chooseSixByReferenceColumns(noisyRow,referenceRow,w){
  if(!Array.isArray(noisyRow)||noisyRow.length<6) return null;
  if(!Array.isArray(referenceRow)||referenceRow.length!==6) return null;

  const reference=[...referenceRow].sort((a,b)=>a.x-b.x);
  const centers=reference.map(b=>b.x+b.w/2);
  const gaps=[];
  for(let i=1;i<6;i++) gaps.push(centers[i]-centers[i-1]);
  const step=median(gaps);

  if(step<w*.075||step>w*.20) return null;
  if(Math.max(...gaps)-Math.min(...gaps)>step*.25) return null;

  const selected=[];
  const used=new Set();

  for(let col=0;col<6;col++){
    let bestIndex=-1,bestDistance=Infinity;
    for(let i=0;i<noisyRow.length;i++){
      if(used.has(i)) continue;
      const cx=noisyRow[i].x+noisyRow[i].w/2;
      const distance=Math.abs(cx-centers[col]);
      if(distance<bestDistance){
        bestDistance=distance;
        bestIndex=i;
      }
    }
    if(bestIndex<0||bestDistance>step*.30) return null;
    used.add(bestIndex);
    selected.push(noisyRow[bestIndex]);
  }

  selected.sort((a,b)=>a.x-b.x);
  const selectedCenters=selected.map(b=>b.x+b.w/2);
  const selectedGaps=[];
  for(let i=1;i<6;i++) selectedGaps.push(selectedCenters[i]-selectedCenters[i-1]);
  const selectedStep=median(selectedGaps);
  if(selectedStep<w*.075||selectedStep>w*.20) return null;
  if(Math.max(...selectedGaps)-Math.min(...selectedGaps)>selectedStep*.25) return null;

  return selected;
}

function recoverNoisySecondRow(boxes,w,h){
  // Try progressively more tolerant Y clustering. We are looking for one
  // trustworthy six-card row and another band containing at least five boxes
  // that line up with five of the six reference columns.
  for(const tolerance of [h*.065,h*.080,h*.100]){
    const rows=clusterBoxesIntoRows(boxes,tolerance)
      .filter(row=>row.length>=4)
      .sort((a,b)=>rowMeanY(a)-rowMeanY(b));

    for(let fullIndex=0;fullIndex<rows.length;fullIndex++){
      const fullSelected=chooseRegularSixBoxes(rows[fullIndex],w);
      if(!fullSelected) continue;

      const full=[...fullSelected].sort((a,b)=>a.x-b.x);
      const centers=full.map(b=>b.x+b.w/2);
      const gaps=[];
      for(let i=1;i<centers.length;i++) gaps.push(centers[i]-centers[i-1]);
      const step=median(gaps);
      if(step<w*.075 || step>w*.20) continue;
      if(Math.max(...gaps)-Math.min(...gaps)>step*.25) continue;

      for(let shortIndex=0;shortIndex<rows.length;shortIndex++){
        if(shortIndex===fullIndex) continue;

        const shortRow=rows[shortIndex];
        if(shortRow.length<5 || shortRow.length>7) continue;

        // The two actual card rows must be meaningfully separated vertically.
        const yGap=Math.abs(rowMeanY(shortRow)-rowMeanY(full));
        if(yGap<h*.10 || yGap>h*.38) continue;

        // Keep the closest candidate for each expected column. A stray box can
        // therefore be ignored while five real boxes still identify the row.
        const matched=new Map();

        for(const b of shortRow){
          const cx=b.x+b.w/2;
          let bestCol=-1,bestDist=Infinity;
          for(let col=0;col<centers.length;col++){
            const d=Math.abs(cx-centers[col]);
            if(d<bestDist){bestDist=d;bestCol=col;}
          }

          if(bestCol<0 || bestDist>step*.32) continue;

          const prior=matched.get(bestCol);
          if(!prior || bestDist<prior.dist){
            matched.set(bestCol,{box:b,dist:bestDist});
          }
        }

        if(matched.size!==5) continue;

        let missingColumn=-1;
        for(let col=0;col<6;col++){
          if(!matched.has(col)){missingColumn=col;break;}
        }
        if(missingColumn<0) continue;

        const good=[...matched.values()].map(v=>v.box);
        const widths=good.map(b=>b.w).sort((a,b)=>a-b);
        const heights=good.map(b=>b.h).sort((a,b)=>a-b);
        const ys=good.map(b=>b.y).sort((a,b)=>a-b);
        const boxW=median(widths);
        const boxH=median(heights);
        const rowY=median(ys);

        const reconstructed={
          x:centers[missingColumn]-boxW/2,
          y:rowY,
          w:boxW,
          h:boxH,
          area:Math.round(boxW*boxH*.35),
          recovered:true
        };

        if(
          reconstructed.x<0 ||
          reconstructed.x+reconstructed.w>w ||
          reconstructed.y<0 ||
          reconstructed.y+reconstructed.h>h
        ) continue;

        // Drop boxes in this damaged band that were not selected as the five
        // good cards, then append the reconstructed sixth card.
        const goodSet=new Set(good);
        const repaired=boxes.filter(b=>{
          if(goodSet.has(b)) return true;
          if(full.includes(b)) return true;

          const by=b.y+b.h/2;
          const shortY=rowMeanY(shortRow);
          return Math.abs(by-shortY)>tolerance;
        });

        repaired.push(reconstructed);

        return {
          boxes:repaired,
          missingColumn
        };
      }
    }
  }

  return null;
}

function recoverMissingBoxFromEleven(boxes,w,h){
  const rows=clusterBoxesIntoRows(boxes,h*.060)
    .filter(row=>row.length>=5)
    .sort((a,b)=>rowMeanY(a)-rowMeanY(b));

  if(rows.length<2) return null;

  let pair=null;
  for(let i=0;i<rows.length;i++){
    for(let j=i+1;j<rows.length;j++){
      const counts=[rows[i].length,rows[j].length].sort((a,b)=>a-b);
      if(counts[0]===5 && counts[1]===6){
        pair=[rows[i],rows[j]];
        break;
      }
    }
    if(pair) break;
  }
  if(!pair) return null;

  let fullRow=pair[0].length===6 ? pair[0] : pair[1];
  let shortRow=pair[0].length===5 ? pair[0] : pair[1];

  fullRow=[...fullRow].sort((a,b)=>a.x-b.x);
  shortRow=[...shortRow].sort((a,b)=>a.x-b.x);

  const fullCenters=fullRow.map(b=>b.x+b.w/2);
  const gaps=[];
  for(let i=1;i<fullCenters.length;i++) gaps.push(fullCenters[i]-fullCenters[i-1]);
  const step=median(gaps);

  if(step<w*.075 || step>w*.20) return null;

  const spread=Math.max(...gaps)-Math.min(...gaps);
  if(spread>step*.25) return null;

  const matched=new Set();
  for(const b of shortRow){
    const cx=b.x+b.w/2;
    let best=-1,bestDist=Infinity;
    for(let i=0;i<fullCenters.length;i++){
      const d=Math.abs(cx-fullCenters[i]);
      if(d<bestDist){ bestDist=d; best=i; }
    }
    if(best<0 || bestDist>step*.30 || matched.has(best)) return null;
    matched.add(best);
  }

  if(matched.size!==5) return null;

  let missingColumn=-1;
  for(let i=0;i<6;i++){
    if(!matched.has(i)){ missingColumn=i; break; }
  }
  if(missingColumn<0) return null;

  const widths=shortRow.map(b=>b.w).sort((a,b)=>a-b);
  const heights=shortRow.map(b=>b.h).sort((a,b)=>a-b);
  const ys=shortRow.map(b=>b.y).sort((a,b)=>a-b);

  const boxW=median(widths);
  const boxH=median(heights);
  const rowY=median(ys);
  const cx=fullCenters[missingColumn];

  const reconstructed={
    x:cx-boxW/2,
    y:rowY,
    w:boxW,
    h:boxH,
    area:Math.round(boxW*boxH*.35),
    recovered:true
  };

  if(
    reconstructed.x<0 ||
    reconstructed.x+reconstructed.w>w ||
    reconstructed.y<0 ||
    reconstructed.y+reconstructed.h>h
  ) return null;

  return {
    boxes:[...boxes,reconstructed],
    missingColumn
  };
}

/*
 * V8.15 fallback: projection-based grid recovery.
 *
 * The primary detector needs 12 clean connected frame components. On some
 * screenshots compression, badges, and mixed card-border colors split or merge
 * those components. The fallback uses horizontal/vertical border density and
 * regular six-column spacing instead.
 */
function detectGridGeometryFallback(img){
  const maxW=1100;
  const scale=Math.min(1,maxW/img.naturalWidth);
  const w=Math.max(1,Math.round(img.naturalWidth*scale));
  const h=Math.max(1,Math.round(img.naturalHeight*scale));

  const c=document.createElement("canvas");
  c.width=w;c.height=h;
  const ctx=c.getContext("2d",{willReadFrequently:true});
  ctx.drawImage(img,0,0,w,h);
  const data=ctx.getImageData(0,0,w,h).data;

  const rowDensity=new Float32Array(h);
  const xStart=Math.floor(w*.06);
  const xEnd=Math.floor(w*.94);

  for(let y=0;y<h;y++){
    let hit=0,total=0;
    for(let x=xStart;x<xEnd;x+=2){
      const i=(y*w+x)*4;
      if(isCategoryBorderRgb(data[i],data[i+1],data[i+2])) hit++;
      total++;
    }
    rowDensity[y]=hit/Math.max(total,1);
  }

  const smoothRows=smoothArray(
    rowDensity,
    Math.max(2,Math.round(h*.004))
  );

  const attempts=[
    {threshold:.045,minRow:.090,merge:.018},
    {threshold:.035,minRow:.080,merge:.022},
    {threshold:.027,minRow:.070,merge:.028},
    {threshold:.020,minRow:.058,merge:.034},
    {threshold:.015,minRow:.050,merge:.040}
  ];

  let lastFailure="no-row-bands";

  for(let attemptIndex=0;attemptIndex<attempts.length;attemptIndex++){
    const cfg=attempts[attemptIndex];

    let intervals=findIntervals(
      smoothRows,
      v=>v>cfg.threshold,
      Math.max(8,Math.round(h*cfg.minRow))
    ).filter(([a,b])=>
      a>h*.24 &&
      a<h*.84 &&
      b>h*.33 &&
      b<h*.94
    );

    intervals=mergeNearbyIntervals(
      intervals,
      Math.round(h*cfg.merge)
    );

    if(intervals.length<2){
      lastFailure=`attempt=${attemptIndex+1} | rowBands=${intervals.length} | threshold=${cfg.threshold.toFixed(3)}`;
      continue;
    }

    const ranked=intervals.map(interval=>{
      const [a,b]=interval;
      let strength=0;
      for(let y=a;y<=b;y++) strength+=rowDensity[y];
      const height=b-a+1;
      const plausible=(height>=h*.085&&height<=h*.29)?1:0;
      return {interval,strength,height,plausible};
    }).sort((a,b)=>
      (b.plausible-a.plausible) ||
      (b.strength-a.strength) ||
      (b.height-a.height)
    ).slice(0,Math.min(6,intervals.length));

    const pairs=[];
    for(let i=0;i<ranked.length;i++){
      for(let j=i+1;j<ranked.length;j++){
        let one=ranked[i].interval;
        let two=ranked[j].interval;
        if(one[0]>two[0]) [one,two]=[two,one];

        const gap=two[0]-one[1];
        if(gap<h*.010||gap>h*.20) continue;

        pairs.push([one,two]);
      }
    }

    if(!pairs.length){
      lastFailure=`attempt=${attemptIndex+1} | rowBands=${intervals.length} | reason=no-plausible-row-pair`;
      continue;
    }

    for(const pair of pairs){
      const allBoxes=[];
      const rows=[];
      const rowDiagnostics=[];
      let pairFailed=false;

      for(let rowIndex=0;rowIndex<2;rowIndex++){
        const [top,bottom]=pair[rowIndex];
        const xDensity=new Float32Array(w);
        const inset=Math.max(2,Math.round((bottom-top)*.07));
        const y0=Math.max(0,top+inset);
        const y1=Math.min(h,bottom-inset);

        for(let x=0;x<w;x++){
          let hit=0,total=0;
          for(let y=y0;y<y1;y+=2){
            const i=(y*w+x)*4;
            if(isCategoryBorderRgb(data[i],data[i+1],data[i+2])) hit++;
            total++;
          }
          xDensity[x]=hit/Math.max(total,1);
        }

        const sx=smoothArray(
          xDensity,
          Math.max(1,Math.round(w*.0015))
        );

        let centers=null;
        let selectedThreshold=null;
        let stripeCount=0;

        for(const threshold of [.22,.18,.15,.12,.10,.08,.06]){
          const stripes=findIntervals(
            sx,
            v=>v>threshold,
            Math.max(2,Math.round(w*.0015))
          );

          const stripeCenters=stripes
            .map(([a,b])=>(a+b)/2)
            .filter(x=>x>w*.05&&x<w*.95);

          let centerCandidates=[];

          for(let i=0;i<stripeCenters.length;i++){
            for(let j=i+1;j<stripeCenters.length;j++){
              const width=stripeCenters[j]-stripeCenters[i];
              if(width>w*.038&&width<w*.120){
                centerCandidates.push(
                  (stripeCenters[i]+stripeCenters[j])/2
                );
              }
            }
          }

          centerCandidates=cluster1D(
            centerCandidates,
            Math.max(6,w*.020)
          ).map(g=>g.mean);

          let regular=chooseRegularSix(centerCandidates,w);

          if(!regular){
            const peaks=topPeaks(
              sx,
              30,
              Math.max(7,Math.round(w*.022))
            );

            const peakGroups=cluster1D(
              peaks,
              Math.max(8,w*.032)
            ).map(g=>g.mean);

            regular=chooseRegularSix(peakGroups,w);
          }

          if(regular){
            centers=[...regular].sort((a,b)=>a-b);
            selectedThreshold=threshold;
            stripeCount=stripes.length;
            break;
          }
        }

        if(!centers){
          lastFailure=`attempt=${attemptIndex+1} | row${rowIndex+1}=no-six-centers`;
          pairFailed=true;
          break;
        }

        const gaps=[];
        for(let i=1;i<centers.length;i++){
          gaps.push(centers[i]-centers[i-1]);
        }

        const step=median(gaps);
        const minGap=Math.min(...gaps);
        const maxGap=Math.max(...gaps);
        const spread=maxGap-minGap;

        if(step<w*.085||step>w*.205||spread>step*.32){
          lastFailure=
            `attempt=${attemptIndex+1} | row${rowIndex+1}=irregular-spacing`+
            `(step=${step.toFixed(1)},spread=${spread.toFixed(1)})`;
          pairFailed=true;
          break;
        }

        const cardW=Math.max(
          w*.050,
          Math.min(w*.105,step*.70)
        );

        const rawH=bottom-top+1;
        const cardH=Math.max(
          h*.095,
          Math.min(h*.265,rawH)
        );

        const rowBoxes=centers.map(cx=>({
          x:(cx-cardW/2)/scale,
          y:top/scale,
          w:cardW/scale,
          h:cardH/scale
        }));

        allBoxes.push(...rowBoxes);
        rows.push([top/scale,(top+cardH)/scale]);
        rowDiagnostics.push(
          `r${rowIndex+1}=threshold:${selectedThreshold.toFixed(2)},stripes:${stripeCount},step:${step.toFixed(1)}`
        );
      }

      if(pairFailed||allBoxes.length!==12) continue;

      const panelLeft=Math.min(...allBoxes.map(b=>b.x));
      const panelRight=Math.max(...allBoxes.map(b=>b.x+b.w));

      // Sanity check total grid width.
      const gridWidth=(panelRight-panelLeft)*scale;
      if(gridWidth<w*.48||gridWidth>w*.88){
        lastFailure=
          `attempt=${attemptIndex+1} | reason=bad-grid-width(${gridWidth.toFixed(1)})`;
        continue;
      }

      return {
        geometry:{
          scale,
          panelLeft,
          panelRight,
          row1:rows[0],
          row2:rows[1],
          boxes:allBoxes,
          gridMethod:"fallback"
        },
        summary:
          `attempt=${attemptIndex+1} | `+
          `rowBands=${pair.map(r=>`${r[0]}-${r[1]}`).join(",")} | `+
          `${rowDiagnostics.join(" | ")} | boxes=12`
      };
    }
  }

  return {
    geometry:null,
    summary:lastFailure
  };
}

function closeBinaryMask(mask,w,h,rx,ry,iterations=1){
  let cur=mask;
  for(let iter=0;iter<iterations;iter++){
    const dilated=new Uint8Array(cur.length);
    for(let y=0;y<h;y++){
      for(let x=0;x<w;x++){
        let hit=0;
        for(let dy=-ry;dy<=ry&&!hit;dy++){
          const yy=y+dy;
          if(yy<0||yy>=h) continue;
          for(let dx=-rx;dx<=rx;dx++){
            const xx=x+dx;
            if(xx>=0&&xx<w&&cur[yy*w+xx]){hit=1;break;}
          }
        }
        dilated[y*w+x]=hit;
      }
    }

    const eroded=new Uint8Array(cur.length);
    for(let y=0;y<h;y++){
      for(let x=0;x<w;x++){
        let all=1;
        for(let dy=-ry;dy<=ry&&all;dy++){
          const yy=y+dy;
          if(yy<0||yy>=h){all=0;break;}
          for(let dx=-rx;dx<=rx;dx++){
            const xx=x+dx;
            if(xx<0||xx>=w||!dilated[yy*w+xx]){all=0;break;}
          }
        }
        eroded[y*w+x]=all;
      }
    }
    cur=eroded;
  }
  return cur;
}

function findBinaryComponents(mask,w,h){
  const seen=new Uint8Array(mask.length);
  const comps=[];
  for(let idx=0;idx<mask.length;idx++){
    if(!mask[idx]||seen[idx]) continue;
    const stack=[idx];
    seen[idx]=1;
    let minX=w,minY=h,maxX=-1,maxY=-1,count=0;
    while(stack.length){
      const cur=stack.pop();
      const y=Math.floor(cur/w);
      const x=cur-y*w;
      minX=Math.min(minX,x);minY=Math.min(minY,y);
      maxX=Math.max(maxX,x);maxY=Math.max(maxY,y);
      count++;
      for(const [dx,dy] of [[1,0],[-1,0],[0,1],[0,-1]]){
        const nx=x+dx,ny=y+dy;
        if(nx<0||ny<0||nx>=w||ny>=h) continue;
        const ni=ny*w+nx;
        if(mask[ni]&&!seen[ni]){seen[ni]=1;stack.push(ni);}
      }
    }
    comps.push({minX,minY,maxX,maxY,count});
  }
  return comps;
}

function clusterBoxesIntoRows(boxes,tolerance){
  const rows=[];
  for(const b of boxes){
    const cy=b.y+b.h/2;
    let target=null,best=Infinity;
    for(const row of rows){
      const d=Math.abs(cy-rowMeanY(row));
      if(d<tolerance&&d<best){target=row;best=d;}
    }
    if(target) target.push(b); else rows.push([b]);
  }
  return rows;
}

function rowMeanY(row){
  return row.reduce((s,b)=>s+b.y+b.h/2,0)/Math.max(row.length,1);
}

function chooseRegularSixBoxes(row,w){
  const candidates=[...row].sort((a,b)=>a.x-b.x);
  if(candidates.length<6) return null;
  if(candidates.length===6) return candidates;

  let best=null,bestCost=Infinity;
  const n=candidates.length;
  function walk(start,chosen){
    if(chosen.length===6){
      const centers=chosen.map(b=>b.x+b.w/2);
      const gaps=[];
      for(let i=1;i<6;i++) gaps.push(centers[i]-centers[i-1]);
      const m=median(gaps);
      if(m<w*.09||m>w*.20) return;
      const variance=gaps.reduce((s,g)=>s+(g-m)*(g-m),0)/gaps.length;
      if(variance<bestCost){bestCost=variance;best=[...chosen];}
      return;
    }
    for(let i=start;i<n;i++){
      chosen.push(candidates[i]);walk(i+1,chosen);chosen.pop();
    }
  }
  walk(0,[]);
  return best;
}

function mergeNearbyIntervals(intervals,maxGap){
  if(!intervals.length) return [];
  const sorted=[...intervals].sort((a,b)=>a[0]-b[0]);
  const out=[sorted[0].slice()];
  for(let i=1;i<sorted.length;i++){
    const cur=sorted[i], last=out[out.length-1];
    if(cur[0]-last[1]<=maxGap) last[1]=Math.max(last[1],cur[1]);
    else out.push(cur.slice());
  }
  return out;
}

function cluster1D(values,tolerance){
  if(!values.length) return [];
  const sorted=[...values].sort((a,b)=>a-b);
  const groups=[];
  let current=[sorted[0]];
  for(let i=1;i<sorted.length;i++){
    if(sorted[i]-current[current.length-1]<=tolerance){
      current.push(sorted[i]);
    } else {
      groups.push(current);
      current=[sorted[i]];
    }
  }
  groups.push(current);
  return groups.map(v=>({
    values:v,
    mean:v.reduce((a,b)=>a+b,0)/v.length
  }));
}

function topPeaks(values,count,minDistance){
  const idx=[...Array(values.length).keys()]
    .sort((a,b)=>values[b]-values[a]);

  const out=[];
  for(const i of idx){
    if(values[i]<.08) break;
    if(out.every(x=>Math.abs(x-i)>=minDistance)){
      out.push(i);
      if(out.length>=count) break;
    }
  }
  return out.sort((a,b)=>a-b);
}

function chooseRegularSix(values,w){
  values=[...new Set(values.map(v=>Math.round(v*10)/10))]
    .filter(v=>v>w*.07&&v<w*.93)
    .sort((a,b)=>a-b);

  if(values.length<6) return null;

  let best=null,bestCost=Infinity;

  // Search combinations of 6 candidate centers and prefer equal spacing.
  // Number of candidates is small after clustering.
  const n=values.length;
  for(let a=0;a<n-5;a++)
  for(let b=a+1;b<n-4;b++)
  for(let c=b+1;c<n-3;c++)
  for(let d=c+1;d<n-2;d++)
  for(let e=d+1;e<n-1;e++)
  for(let f=e+1;f<n;f++){
    const arr=[values[a],values[b],values[c],values[d],values[e],values[f]];
    const gaps=[];
    for(let i=1;i<6;i++) gaps.push(arr[i]-arr[i-1]);
    const m=median(gaps);
    if(m<w*.09||m>w*.20) continue;
    const variance=gaps.reduce((s,g)=>s+(g-m)*(g-m),0)/gaps.length;
    const edgePenalty=Math.abs(arr[0]-w*.14)+Math.abs(arr[5]-w*.86);
    const cost=variance+edgePenalty*.03;
    if(cost<bestCost){bestCost=cost;best=arr;}
  }

  return best;
}

function median(values){
  const a=[...values].sort((x,y)=>x-y);
  const m=Math.floor(a.length/2);
  return a.length%2?a[m]:(a[m-1]+a[m])/2;
}

function scoreAllPages(img,geometry){
  const slotEvidence=geometry.boxes.map(box=>sampleCardCategoryEvidence(img,box));
  const signature=slotEvidence.map(e=>e.category).join("");
  const pageScores=[];

  PAGE_SIGNATURES.forEach((expected,pageIndex)=>{
    let weighted=0;
    let rawMatches=0;

    for(let i=0;i<12;i++){
      const ev=slotEvidence[i];
      const want=expected[i];

      if(ev.category===want){
        rawMatches++;
        weighted += 1.0 + Math.min(ev.margin,1.0)*0.85;
      } else if(ev.secondCategory===want){
        weighted += 0.45*(1.0-Math.min(ev.margin,1.0));
      } else if(ev.category==="?"){
        weighted += 0.10;
      } else {
        weighted -= 0.35 + Math.min(ev.margin,1.0)*0.45;
      }
    }

    if(pageIndex===0){
      const elixirVotes=slotEvidence.filter(e=>
        e.category==="E" || e.secondCategory==="E"
      ).length;
      if(elixirVotes>=10) weighted+=1.2;
      if(elixirVotes===12) weighted+=0.8;
    }

    pageScores.push({
      page:pageIndex,
      weighted,
      rawMatches
    });
  });

  return {signature,slotEvidence,pageScores};
}

function assignPagesGlobally(candidates){
  if(!candidates.length){
    return {items:[],unassigned:[]};
  }

  // We expect up to five screenshots. Find the one-to-one assignment with
  // highest total weighted score. This prevents duplicate page claims and lets
  // a weak screenshot inherit the only remaining page when the other four are
  // strongly identified.
  const usable=candidates.slice(0,5);
  const pages=[0,1,2,3,4];

  let best=null;

  function permute(arr,l=0){
    if(l===arr.length){
      const assignedPages=arr.slice(0,usable.length);
      let total=0;
      const items=[];

      for(let i=0;i<usable.length;i++){
        const p=assignedPages[i];
        const scoreObj=usable[i].pageResult.pageScores[p];
        total += scoreObj.weighted;

        items.push({
          candidate:usable[i],
          page:p,
          score:scoreObj.weighted,
          rawMatches:scoreObj.rawMatches
        });
      }

      // Penalize implausibly weak assignments, but don't make them impossible.
      // This still allows the "remaining page" logic to rescue page 1.
      for(const item of items){
        if(item.score<5) total-=2;
      }

      if(!best || total>best.total){
        best={total,items};
      }
      return;
    }

    for(let i=l;i<arr.length;i++){
      [arr[l],arr[i]]=[arr[i],arr[l]];
      permute(arr,l+1);
      [arr[l],arr[i]]=[arr[i],arr[l]];
    }
  }

  permute(pages);

  // If fewer than five screenshots were supplied, keep only the first N
  // assignments from each permutation.
  const assignedCandidates=new Set((best?.items||[]).map(x=>x.candidate));
  const unassigned=candidates.filter(c=>!assignedCandidates.has(c));

  return {
    items:best?.items||[],
    unassigned,
    totalScore:best?.total??0
  };
}

function formatPageScores(pageScores){
  return pageScores
    .map(s=>`P${s.page+1}:${s.weighted.toFixed(2)}/${s.rawMatches}`)
    .join(",");
}

function sampleCardCategoryEvidence(img,box){
  const c=imageCanvas(img);
  const ctx=c.getContext("2d",{willReadFrequently:true});
  const counts={E:0,D:0,B:0,S:0};

  // Sample several narrow frame regions, but weight the left/right vertical
  // borders more heavily than the top edge because the top may be obscured by
  // badge/artwork overlap on some tablet screenshots.
  const strips=[
    {x:box.x+box.w*.01,  y:box.y+box.h*.10, w:box.w*.050, h:box.h*.68, weight:1.35},
    {x:box.x+box.w*.94,  y:box.y+box.h*.10, w:box.w*.050, h:box.h*.68, weight:1.35},
    {x:box.x+box.w*.12,  y:box.y+box.h*.01, w:box.w*.76,  h:box.h*.050,weight:0.65},
    {x:box.x+box.w*.08,  y:box.y+box.h*.70, w:box.w*.84,  h:box.h*.050,weight:0.55}
  ];

  for(const s of strips){
    const sx=Math.max(0,Math.round(s.x));
    const sy=Math.max(0,Math.round(s.y));
    const sw=Math.max(2,Math.min(c.width-sx,Math.round(s.w)));
    const sh=Math.max(2,Math.min(c.height-sy,Math.round(s.h)));
    if(sw<=0||sh<=0) continue;

    const d=ctx.getImageData(sx,sy,sw,sh).data;

    for(let i=0;i<d.length;i+=4){
      const hsv=rgbToHsv(d[i],d[i+1],d[i+2]);
      if(hsv.s<.34||hsv.v<.26) continue;

      const cat=hueCategory(hsv.h);
      if(cat!=="?") counts[cat]+=s.weight;
    }
  }

  const ranked=["E","D","B","S"]
    .map(category=>({category,count:counts[category]}))
    .sort((a,b)=>b.count-a.count);

  const first=ranked[0];
  const second=ranked[1];
  const total=ranked.reduce((s,x)=>s+x.count,0);

  if(total<=0){
    return {
      category:"?",
      secondCategory:"?",
      margin:0,
      counts
    };
  }

  const margin=(first.count-second.count)/Math.max(first.count,1);

  // Very weak/ambiguous votes should be marked unknown rather than forcing an
  // incorrect category letter into the signature.
  const category =
    first.count<6 || margin<0.08 ? "?" : first.category;

  return {
    category,
    secondCategory:second.category,
    margin,
    counts
  };
}

function sampleCardCategory(img,box){
  const c=imageCanvas(img);
  const ctx=c.getContext("2d",{willReadFrequently:true});
  const counts={E:0,D:0,B:0,S:0,"?":0};

  // Vote from several narrow strips around the colored frame. This is much
  // less likely to sample portrait artwork than the old single left-edge probe.
  const strips=[
    [box.x+box.w*.02, box.y+box.h*.12, box.w*.055, box.h*.64],
    [box.x+box.w*.925,box.y+box.h*.12, box.w*.055, box.h*.64],
    [box.x+box.w*.12, box.y+box.h*.015,box.w*.76, box.h*.055]
  ];

  for(const [x,y,w,h] of strips){
    const sx=Math.max(0,Math.round(x));
    const sy=Math.max(0,Math.round(y));
    const sw=Math.max(2,Math.min(c.width-sx,Math.round(w)));
    const sh=Math.max(2,Math.min(c.height-sy,Math.round(h)));
    const d=ctx.getImageData(sx,sy,sw,sh).data;

    for(let i=0;i<d.length;i+=4){
      const hsv=rgbToHsv(d[i],d[i+1],d[i+2]);
      if(hsv.s<.40||hsv.v<.30) continue;
      const cat=hueCategory(hsv.h);
      counts[cat]=(counts[cat]||0)+1;
    }
  }

  const ranked=["E","D","B","S"].sort((a,b)=>counts[b]-counts[a]);
  return counts[ranked[0]]>0?ranked[0]:"?";
}

/* ---------------- Card state + quantity ---------------- */

function analyzeCardPixels(img,box){
  const c=imageCanvas(img);
  const ctx=c.getContext("2d",{willReadFrequently:true});

  const ix=Math.max(0,Math.round(box.x+box.w*.12));
  const iy=Math.max(0,Math.round(box.y+box.h*.08));
  const iw=Math.max(4,Math.min(c.width-ix,Math.round(box.w*.76)));
  const ih=Math.max(4,Math.min(c.height-iy,Math.round(box.h*.64)));
  const d=ctx.getImageData(ix,iy,iw,ih).data;

  let satSum=0,n=0;
  for(let i=0;i<d.length;i+=16){
    satSum+=rgbToHsv(d[i],d[i+1],d[i+2]).s;
    n++;
  }

  const avgSat=satSum/Math.max(n,1);
  return {
    grayscale:avgSat<0.10,
    avgSat
  };
}

function extractBadge(img,box){
  const source=imageCanvas(img);
  const ctx=source.getContext("2d",{willReadFrequently:true});

  // V8.33: the colored card-frame component sometimes stops just above the
  // quantity badge. Search a little wider and, importantly, slightly below the
  // detected frame instead of assuming the badge is completely inside it.
  const sx=Math.max(0,Math.round(box.x+box.w*.05));
  const sy=Math.max(0,Math.round(box.y+box.h*.60));
  const sw=Math.max(8,Math.min(source.width-sx,Math.round(box.w*.90)));
  const desiredBottom=box.y+box.h*1.13;
  const sh=Math.max(8,Math.min(source.height-sy,Math.round(desiredBottom-sy)));
  if(sw<=0||sh<=0) return null;

  const id=ctx.getImageData(sx,sy,sw,sh);
  const d=id.data;
  let mask=new Uint8Array(sw*sh);

  for(let y=0;y<sh;y++){
    for(let x=0;x<sw;x++){
      const i=(y*sw+x)*4;
      const hsv=rgbToHsv(d[i],d[i+1],d[i+2]);

      // Yellow/gold badge face.
      if(hsv.h>=28&&hsv.h<=75&&hsv.s>.38&&hsv.v>.50){
        mask[y*sw+x]=1;
      }
    }
  }

  // Close through black glyph holes so x2/x3/x4/x6 remain one component.
  const rx=Math.max(2,Math.round(sw*.025));
  const ry=Math.max(1,Math.round(sh*.018));
  mask=closeBinaryMask(mask,sw,sh,rx,ry,1);

  const components=findMaskComponents(mask,sw,sh);
  if(!components.length) return null;

  const candidates=components.map(comp=>{
    const bw=comp.maxX-comp.minX+1;
    const bh=comp.maxY-comp.minY+1;
    const area=bw*bh;
    const fill=comp.count/Math.max(area,1);
    const aspect=bw/Math.max(bh,1);

    const centerX=(comp.minX+comp.maxX)/2;
    const centerY=(comp.minY+comp.maxY)/2;

    const relX=(sx+centerX-box.x)/box.w;
    const relY=(sy+centerY-box.y)/box.h;
    const relW=bw/box.w;
    const relH=bh/box.h;

    // Real badges in our verified data cluster around:
    //   X center ≈ 0.38–0.50
    //   Y center ≈ 0.90–0.94
    //   width    ≈ 0.28–0.55 card width
    //   height   ≈ 0.11–0.20 card height
    //
    // Use soft scoring inside a hard envelope.
    let score=comp.count;

    const xPenalty=Math.abs(relX-.43)/.20;
    const yPenalty=Math.abs(relY-.92)/.08;
    const wPenalty=Math.abs(relW-.34)/.25;
    const hPenalty=Math.abs(relH-.14)/.10;

    score *= Math.max(.05,1-xPenalty*.45);
    score *= Math.max(.05,1-yPenalty*.70);
    score *= Math.max(.10,1-wPenalty*.30);
    score *= Math.max(.10,1-hPenalty*.35);

    if(fill<.20) score*=.25;
    if(aspect<1.35||aspect>4.9) score*=.15;

    return {
      comp,bw,bh,fill,aspect,
      relX,relY,relW,relH,score
    };
  }).filter(x=>
    // Hard geometry lock. These bounds are based on verified real badges from
    // V8.7 logs and intentionally exclude false artwork hits such as
    // Sneaky Archer at Y≈0.81 and oversized Archer artwork.
    x.relX>=.20 && x.relX<=.70 &&
    x.relY>=.84 && x.relY<=1.08 &&
    x.relW>=.18 && x.relW<=.66 &&
    x.relH>=.060 && x.relH<=.26 &&
    x.aspect>=1.25 && x.aspect<=5.2 &&
    x.fill>=.16
  ).sort((a,b)=>b.score-a.score);

  if(!candidates.length) return null;

  const best=candidates[0];
  const comp=best.comp;

  const padX=Math.max(2,Math.round(best.bw*.13));
  const padY=Math.max(2,Math.round(best.bh*.20));

  const x0=Math.max(0,comp.minX-padX);
  const y0=Math.max(0,comp.minY-padY);
  const x1=Math.min(sw-1,comp.maxX+padX);
  const y1=Math.min(sh-1,comp.maxY+padY);

  const absX=sx+x0,absY=sy+y0;
  const absW=x1-x0+1,absH=y1-y0+1;
  if(absW<=0||absH<=0) return null;

  const normalizedCanvas=document.createElement("canvas");
  normalizedCanvas.width=58;
  normalizedCanvas.height=28;
  const nctx=normalizedCanvas.getContext("2d",{willReadFrequently:true});
  nctx.imageSmoothingEnabled=true;
  nctx.drawImage(source,absX,absY,absW,absH,0,0,58,28);

  const ocrCanvas=document.createElement("canvas");
  ocrCanvas.width=420;
  ocrCanvas.height=200;
  const octx=ocrCanvas.getContext("2d",{willReadFrequently:true});
  octx.imageSmoothingEnabled=true;
  octx.drawImage(source,absX,absY,absW,absH,0,0,420,200);

  const fd=ctx.getImageData(absX,absY,absW,absH).data;
  let yellow=0,dark=0,total=0;

  for(let i=0;i<fd.length;i+=4){
    const hsv=rgbToHsv(fd[i],fd[i+1],fd[i+2]);
    const lum=.299*fd[i]+.587*fd[i+1]+.114*fd[i+2];

    if(hsv.h>=28&&hsv.h<=75&&hsv.s>.35&&hsv.v>.48) yellow++;
    if(lum<105) dark++;
    total++;
  }

  const yellowRatio=yellow/Math.max(total,1);
  const darkRatio=dark/Math.max(total,1);

  if(yellowRatio<.16||darkRatio<.045) return null;

  return {
    x:absX,y:absY,w:absW,h:absH,
    yellowRatio,darkRatio,
    relX:best.relX,relY:best.relY,
    relW:best.relW,relH:best.relH,
    aspect:best.aspect,
    fill:best.fill,
    normalizedCanvas,ocrCanvas
  };
}

function findMaskComponents(mask,w,h){
  const seen=new Uint8Array(mask.length);
  const comps=[];

  for(let idx=0;idx<mask.length;idx++){
    if(!mask[idx]||seen[idx]) continue;

    const stack=[idx];
    seen[idx]=1;

    let minX=w,minY=h,maxX=-1,maxY=-1,count=0;

    while(stack.length){
      const cur=stack.pop();
      const y=Math.floor(cur/w);
      const x=cur-y*w;

      minX=Math.min(minX,x);
      minY=Math.min(minY,y);
      maxX=Math.max(maxX,x);
      maxY=Math.max(maxY,y);
      count++;

      for(const [dx,dy] of [[1,0],[-1,0],[0,1],[0,-1]]){
        const nx=x+dx,ny=y+dy;
        if(nx<0||ny<0||nx>=w||ny>=h) continue;
        const ni=ny*w+nx;
        if(mask[ni]&&!seen[ni]){
          seen[ni]=1;
          stack.push(ni);
        }
      }
    }

    comps.push({minX,minY,maxX,maxY,count});
  }

  return comps;
}

function formatBadgeBox(badge){
  return `${Math.round(badge.x)},${Math.round(badge.y)},${Math.round(badge.w)}x${Math.round(badge.h)}`;
}

async function loadBadgeTemplates(){
  const urls={
    2:[
      "assets/badges/x2_dyn_01.png",
      "assets/badges/x2_dyn_02.png",
      "assets/badges/x2_dyn_03.png"
    ],
    3:["assets/badges/x3_dyn_01.png"],
    4:["assets/badges/x4_dyn_01.png"],
    6:["assets/badges/x6_dyn_01.png"]
  };

  const out={};

  for(const [qty,list] of Object.entries(urls)){
    const images=await Promise.all(list.map(loadImageUrl));
    out[Number(qty)]=images.map(img=>{
      const c=imageCanvas(img);
      const glyph=extractBadgeGlyph(c);
      return glyph ? glyphVector(glyph.canvas) : canvasTextVector(c);
    });
  }

  // Add human-approved examples previously learned in this browser.
  const learned=getLearnedGlyphs();

  for(const example of learned.examples){
    const qty=Number(example.qty);
    if(!Number.isInteger(qty)||qty<2||qty>99||!example.glyph_data_url) continue;

    try{
      const img=await loadImageUrl(example.glyph_data_url);
      const c=imageCanvas(img);
      const vector=glyphVector(c);
      (out[qty] ||= []).push(vector);
    }catch(e){
      console.warn("Could not load learned glyph",example,e);
    }
  }

  return out;
}

function matchBadgeCanvas(canvas,templates){
  const glyph=extractBadgeGlyph(canvas);

  if(!glyph){
    return {
      qty:null,
      best:1,
      second:1,
      margin:0,
      scores:{},
      glyph:null
    };
  }

  const sample=glyphVector(glyph.canvas);
  const scores={};

  for(const [qty,list] of Object.entries(templates)){
    scores[qty]=Math.min(...list.map(t=>vectorDistance(sample,t)));
  }

  const ranked=Object.entries(scores)
    .map(([qty,score])=>({qty:Number(qty),score}))
    .sort((a,b)=>a.score-b.score);

  return {
    qty:ranked[0]?.qty ?? null,
    best:ranked[0]?.score ?? 1,
    second:ranked[1]?.score ?? 1,
    margin:(ranked[1]?.score ?? 1)-(ranked[0]?.score ?? 1),
    scores,
    glyph
  };
}

function extractBadgeGlyph(sourceCanvas){
  const w=sourceCanvas.width;
  const h=sourceCanvas.height;
  const ctx=sourceCanvas.getContext("2d",{willReadFrequently:true});
  const id=ctx.getImageData(0,0,w,h);
  const d=id.data;

  // Step 1: build yellow badge-body mask.
  let yellow=new Uint8Array(w*h);

  for(let y=0;y<h;y++){
    for(let x=0;x<w;x++){
      const i=(y*w+x)*4;
      const hsv=rgbToHsv(d[i],d[i+1],d[i+2]);

      if(hsv.h>=28&&hsv.h<=75&&hsv.s>.28&&hsv.v>.42){
        yellow[y*w+x]=1;
      }
    }
  }

  // Close across the black x/digit holes so the yellow badge becomes one
  // continuous body. Then lightly dilate so the mask covers anti-aliased glyph
  // edges while remaining well inside the actual badge footprint.
  const closedYellow=closeBinaryMask(
    yellow,w,h,
    Math.max(1,Math.round(w*.035)),
    Math.max(1,Math.round(h*.045)),
    1
  );

  const badgeComps=findMaskComponents(closedYellow,w,h);
  if(!badgeComps.length) return null;

  // Pick the dominant yellow badge body, favoring central/lower components.
  const badgeCandidates=badgeComps.map(comp=>{
    const bw=comp.maxX-comp.minX+1;
    const bh=comp.maxY-comp.minY+1;
    const area=bw*bh;
    const fill=comp.count/Math.max(area,1);
    const cx=(comp.minX+comp.maxX)/2;
    const cy=(comp.minY+comp.maxY)/2;

    let score=comp.count;
    score*=Math.max(.2,1-Math.abs(cx-w*.5)/(w*.5)*.45);
    score*=Math.max(.2,1-Math.abs(cy-h*.55)/(h*.55)*.25);

    return {comp,bw,bh,fill,score};
  }).filter(x=>
    x.bw>=w*.28 &&
    x.bh>=h*.28 &&
    x.fill>=.20
  ).sort((a,b)=>b.score-a.score);

  if(!badgeCandidates.length) return null;

  const badge=badgeCandidates[0].comp;

  // Interior mask: use the yellow body component's bounding box, inset a little
  // so the dark outer border/shadow is excluded. Unlike V8.9, we do NOT reject
  // dark components merely because they touch the crop edge.
  const insetX=Math.max(1,Math.round((badge.maxX-badge.minX+1)*.06));
  const insetY=Math.max(1,Math.round((badge.maxY-badge.minY+1)*.08));

  const ix0=Math.max(0,badge.minX+insetX);
  const iy0=Math.max(0,badge.minY+insetY);
  const ix1=Math.min(w-1,badge.maxX-insetX);
  const iy1=Math.min(h-1,badge.maxY-insetY);

  if(ix1<=ix0||iy1<=iy0) return null;

  // Step 2: isolate dark pixels only inside that badge interior.
  let darkMask=new Uint8Array(w*h);

  for(let y=iy0;y<=iy1;y++){
    for(let x=ix0;x<=ix1;x++){
      const i=(y*w+x)*4;
      const r=d[i],g=d[i+1],b=d[i+2];
      const lum=.299*r+.587*g+.114*b;
      const hsv=rgbToHsv(r,g,b);

      // Fairly permissive darkness threshold because antialiased game text may
      // be dark gray, not pure black.
      if(lum<150 && hsv.v<.76){
        darkMask[y*w+x]=1;
      }
    }
  }

  // Remove isolated noise while preserving the x and digit strokes.
  darkMask=closeBinaryMask(darkMask,w,h,1,1,1);

  const comps=findMaskComponents(darkMask,w,h);

  let glyphComps=comps.map(comp=>{
    const bw=comp.maxX-comp.minX+1;
    const bh=comp.maxY-comp.minY+1;
    const area=bw*bh;
    const fill=comp.count/Math.max(area,1);
    const cx=(comp.minX+comp.maxX)/2;
    const cy=(comp.minY+comp.maxY)/2;

    return {comp,bw,bh,area,fill,cx,cy};
  }).filter(x=>
    x.bw>=1 &&
    x.bh>=Math.max(3,h*.16) &&
    x.area>=4 &&
    x.fill>=.08 &&
    x.cx>=ix0 && x.cx<=ix1 &&
    x.cy>=iy0 && x.cy<=iy1
  );

  if(!glyphComps.length) return null;

  // Keep the central text components. The x and numeric glyph live in the
  // middle of the badge; tiny dark decorations near the edges are ignored.
  glyphComps=glyphComps.filter(x=>
    x.cx>=ix0+(ix1-ix0)*.08 &&
    x.cx<=ix0+(ix1-ix0)*.92 &&
    x.cy>=iy0+(iy1-iy0)*.05 &&
    x.cy<=iy0+(iy1-iy0)*.95
  );

  if(!glyphComps.length) return null;

  // Prefer up to the largest 4 components; this captures 'x' + one/two digits
  // while rejecting stray anti-alias specks.
  glyphComps=glyphComps
    .sort((a,b)=>b.area-a.area)
    .slice(0,4)
    .sort((a,b)=>a.comp.minX-b.comp.minX);

  let minX=Math.min(...glyphComps.map(x=>x.comp.minX));
  let minY=Math.min(...glyphComps.map(x=>x.comp.minY));
  let maxX=Math.max(...glyphComps.map(x=>x.comp.maxX));
  let maxY=Math.max(...glyphComps.map(x=>x.comp.maxY));

  const padX=Math.max(1,Math.round((maxX-minX+1)*.12));
  const padY=Math.max(1,Math.round((maxY-minY+1)*.14));

  minX=Math.max(ix0,minX-padX);
  minY=Math.max(iy0,minY-padY);
  maxX=Math.min(ix1,maxX+padX);
  maxY=Math.min(iy1,maxY+padY);

  const gw=maxX-minX+1;
  const gh=maxY-minY+1;
  if(gw<=2||gh<=2) return null;

  const normalized=document.createElement("canvas");
  normalized.width=72;
  normalized.height=40;
  const nctx=normalized.getContext("2d",{willReadFrequently:true});
  nctx.fillStyle="white";
  nctx.fillRect(0,0,72,40);

  const tmp=document.createElement("canvas");
  tmp.width=gw;
  tmp.height=gh;
  const tctx=tmp.getContext("2d",{willReadFrequently:true});
  const out=tctx.createImageData(gw,gh);

  for(let y=0;y<gh;y++){
    for(let x=0;x<gw;x++){
      const srcX=minX+x;
      const srcY=minY+y;
      const on=darkMask[srcY*w+srcX];
      const i=(y*gw+x)*4;
      const v=on?0:255;
      out.data[i]=out.data[i+1]=out.data[i+2]=v;
      out.data[i+3]=255;
    }
  }

  tctx.putImageData(out,0,0);

  const innerW=64;
  const innerH=32;
  const scale=Math.min(innerW/gw,innerH/gh);
  const dw=Math.max(1,Math.round(gw*scale));
  const dh=Math.max(1,Math.round(gh*scale));
  const dx=Math.round((72-dw)/2);
  const dy=Math.round((40-dh)/2);

  nctx.imageSmoothingEnabled=false;
  nctx.drawImage(tmp,0,0,gw,gh,dx,dy,dw,dh);

  return {
    canvas:normalized,
    x:minX,y:minY,w:gw,h:gh,
    components:glyphComps.length,
    badgeInterior:`${ix0},${iy0},${ix1-ix0+1}x${iy1-iy0+1}`
  };
}

function glyphVector(canvas){
  const ctx=canvas.getContext("2d",{willReadFrequently:true});
  const d=ctx.getImageData(0,0,canvas.width,canvas.height).data;
  const v=new Float32Array(canvas.width*canvas.height);

  let k=0;
  for(let i=0;i<d.length;i+=4){
    const lum=.299*d[i]+.587*d[i+1]+.114*d[i+2];
    v[k++]=lum<128?1:0;
  }

  return v;
}

function acceptTemplateMatch(match){
  const scoreText=Object.entries(match.scores)
    .map(([q,s])=>`x${q}=${s.toFixed(4)}`).join(",");

  // Strong nearest-neighbor matches are cheap and reliable. Anything
  // ambiguous falls through to OCR, which also allows unseen values x5/x7/etc.
  if(match.qty!==null && match.best<=0.28 && match.margin>=0.018){
    return {
      accepted:true,
      qty:match.qty,
      confidence:match.best<=0.10?"high":"medium",
      detail:`template:${scoreText},margin=${match.margin.toFixed(4)}`
    };
  }

  return {
    accepted:false,
    qty:null,
    confidence:"low",
    detail:`template-rejected:${scoreText},margin=${match.margin.toFixed(4)}`
  };
}

function templateVector(img){
  const c=document.createElement("canvas");
  c.width=58;c.height=28;
  const ctx=c.getContext("2d",{willReadFrequently:true});
  ctx.drawImage(img,0,0,58,28);
  return canvasTextVector(c);
}

function canvasTextVector(canvas){
  const ctx=canvas.getContext("2d",{willReadFrequently:true});
  const d=ctx.getImageData(0,0,canvas.width,canvas.height).data;
  const out=[];

  for(let y=3;y<25;y++){
    for(let x=5;x<53;x++){
      const i=(y*canvas.width+x)*4;
      const lum=.299*d[i]+.587*d[i+1]+.114*d[i+2];
      out.push(lum<108?1:0);
    }
  }
  return out;
}

function upscaleGlyphForOcr(glyphCanvas){
  const c=document.createElement("canvas");
  c.width=576;
  c.height=320;
  const ctx=c.getContext("2d",{willReadFrequently:true});
  ctx.fillStyle="white";
  ctx.fillRect(0,0,c.width,c.height);
  ctx.imageSmoothingEnabled=false;
  ctx.drawImage(glyphCanvas,0,0,glyphCanvas.width,glyphCanvas.height,0,0,c.width,c.height);
  return c;
}

async function readBadgeQuantityCanvas(worker,baseCanvas,minConfidence=55){
  await worker.setParameters({
    tessedit_char_whitelist:"xX0123456789",
    tessedit_pageseg_mode:"7"
  });

  const variants=["badge-dark","raw","gray","threshold-dark","threshold-light"];
  const attempts=[];

  for(const variant of variants){
    const c=preprocessBadgeForOcr(baseCanvas,variant);
    const r=await worker.recognize(c);
    const txt=(r.data.text||"").replace(/\s+/g,"").trim();
    const ocrConfidence=Number(r.data.confidence||0);

    attempts.push(`${variant}:${txt}:${ocrConfidence.toFixed(1)}`);

    let m=txt.match(/[xX][^0-9]*([2-9][0-9]?)/);
    if(!m) m=txt.match(/^([2-9][0-9]?)$/);

    if(m){
      const qty=Number(m[1]);

      // The prior build produced many bogus "7" values. Be much more
      // conservative: OCR must have meaningful confidence before it can
      // create a quantity that templates did not support.
      if(qty>=2&&qty<=9 && ocrConfidence>=minConfidence){
        return {
          qty,
          raw:txt,
          confidence:ocrConfidence>=65?"medium":"low",
          ocrConfidence,
          attempts
        };
      }
    }
  }

  return {qty:null,raw:"",confidence:"low",ocrConfidence:0,attempts};
}

function preprocessBadgeForOcr(baseCanvas,variant){
  const c=document.createElement("canvas");
  c.width=baseCanvas.width;
  c.height=baseCanvas.height;
  const ctx=c.getContext("2d",{willReadFrequently:true});
  ctx.drawImage(baseCanvas,0,0);

  if(variant==="raw") return c;

  const id=ctx.getImageData(0,0,c.width,c.height);

  for(let i=0;i<id.data.length;i+=4){
    const lum=.299*id.data[i]+.587*id.data[i+1]+.114*id.data[i+2];
    let v=lum;

    if(variant==="gray") v=lum;
    else if(variant==="threshold-dark") v=lum<130?0:255;
    else if(variant==="threshold-light") v=lum<175?0:255;
    else if(variant==="badge-dark"){
      const hsv=rgbToHsv(id.data[i],id.data[i+1],id.data[i+2]);
      // Preserve the black x/digits and suppress the yellow/gold badge face,
      // colored artwork, and highlights.
      const isDark=lum<145 && hsv.v<.72;
      v=isDark?0:255;
    }

    id.data[i]=id.data[i+1]=id.data[i+2]=v;
  }

  ctx.putImageData(id,0,0);
  return c;
}

/* ---------------- Username: shield-anchored OCR ---------------- */

function normalizeUsernameForLoginCheck(value){
  // Requested release behavior: ignore capitalization and periods.
  // Spaces are retained because they may be meaningful in a player name.
  return String(value||"")
    .trim()
    .toLowerCase()
    .replace(/\./g,"")
    .replace(/\s+/g," ");
}

function renderUsernameLoginWarning(checks,loggedInName){
  if(!usernameReviewWarning) return;

  const readable=checks.filter(c=>c.detected);
  const mismatches=readable.filter(c=>c.matches===false);

  if(!mismatches.length){
    usernameReviewWarning.hidden=true;
    usernameReviewWarning.innerHTML="";
    return;
  }

  const esc=value=>String(value).replace(/[&<>"']/g,ch=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  })[ch]);

  const items=mismatches.map(c=>
    `<li><strong>${esc(c.file)}</strong> — OCR saw “${esc(c.detected)}”</li>`
  ).join("");

  usernameReviewWarning.innerHTML=
    `<strong>Player-name warning</strong>`+
    `You are logged in as “${esc(loggedInName)}”, but one or more screenshots appear to show a different player.`+
    `<ul>${items}</ul>`+
    `<div>You can still save. The inventory will be saved to your logged-in player.</div>`;
  usernameReviewWarning.hidden=false;
}

async function readUsernameForWarning(worker,img){
  await worker.setParameters({
    tessedit_char_whitelist:"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 _.'#-",
    tessedit_pageseg_mode:"7"
  });

  const crops=[
    {
      name:"name-band-a",
      x:img.naturalWidth*.064,
      y:img.naturalHeight*.041,
      w:img.naturalWidth*.082,
      h:img.naturalHeight*.035
    },
    {
      name:"name-band-b",
      x:img.naturalWidth*.058,
      y:img.naturalHeight*.032,
      w:img.naturalWidth*.105,
      h:img.naturalHeight*.046
    },
    {
      name:"name-band-c",
      x:img.naturalWidth*.050,
      y:img.naturalHeight*.025,
      w:img.naturalWidth*.125,
      h:img.naturalHeight*.055
    }
  ];

  const candidates=[];
  for(const crop of crops){
    for(const variant of ["gray","shadow-text"]){
      const canvas=cropUsername(img,crop,variant);
      const r=await worker.recognize(canvas);
      const raw=(r.data.text||"").trim();
      const cleaned=cleanUsername(raw);
      const confidence=Number(r.data.confidence||0);
      if(cleaned){
        candidates.push({raw,cleaned,confidence,variant,source:crop.name});
      }
    }
  }

  if(!candidates.length){
    return {raw:"",cleaned:"",confidence:0,variant:"none",source:"none"};
  }

  candidates.sort((a,b)=>{
    const ap=usernamePlausibility(a.cleaned,a.confidence,a.source);
    const bp=usernamePlausibility(b.cleaned,b.confidence,b.source);
    return (bp-ap)||(b.confidence-a.confidence);
  });

  return candidates[0];
}

async function readUsername(worker,img){
  const shield=findLevelShield(img);
  const candidates=[];

  await worker.setParameters({
    tessedit_char_whitelist:"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 _.'#-",
    tessedit_pageseg_mode:"7"
  });

  const crops=[];

  if(shield){
    // Username starts immediately to the right of the shield and near its top.
    crops.push({
      name:"shield",
      x:shield.x+shield.w*.82,
      y:Math.max(0,shield.y-shield.h*.04),
      w:shield.w*3.1,
      h:shield.h*.46
    });
    crops.push({
      name:"shield-wide",
      x:shield.x+shield.w*.70,
      y:Math.max(0,shield.y-shield.h*.08),
      w:shield.w*3.8,
      h:shield.h*.55
    });
  }

  // V8.31 focused username bands.
  //
  // Desktop/emulator captures often include window chrome and side controls,
  // which makes the old broad top-left crop contain the level shield, XP bar,
  // foliage and builder timers. The actual player name is a narrow horizontal
  // strip. Try several conservative bands first; the older broad region stays
  // as a last resort for unusual devices.
  crops.push({
    name:"name-band-a",
    x:img.naturalWidth*.064,
    y:img.naturalHeight*.041,
    w:img.naturalWidth*.082,
    h:img.naturalHeight*.035
  });
  crops.push({
    name:"name-band-b",
    x:img.naturalWidth*.058,
    y:img.naturalHeight*.032,
    w:img.naturalWidth*.105,
    h:img.naturalHeight*.046
  });
  crops.push({
    name:"name-band-c",
    x:img.naturalWidth*.050,
    y:img.naturalHeight*.025,
    w:img.naturalWidth*.125,
    h:img.naturalHeight*.055
  });

  // Fallback search region for unusual skins/aspect ratios.
  crops.push({
    name:"top-left",
    x:img.naturalWidth*.045,
    y:img.naturalHeight*.005,
    w:img.naturalWidth*.19,
    h:img.naturalHeight*.075
  });

  const variants=["raw","gray","bright-text","threshold","shadow-text"];

  for(const crop of crops){
    for(const variant of variants){
      const canvas=cropUsername(img,crop,variant);
      const r=await worker.recognize(canvas);
      const raw=(r.data.text||"").trim();
      const cleaned=cleanUsername(raw);
      const confidence=Number(r.data.confidence||0);
      if(cleaned) candidates.push({
        raw,cleaned,confidence,variant,source:crop.name
      });
    }
  }

  if(!candidates.length){
    return {raw:"",cleaned:"",confidence:0,variant:"none",source:"none"};
  }

  candidates.sort((a,b)=>{
    const ap=usernamePlausibility(a.cleaned,a.confidence,a.source);
    const bp=usernamePlausibility(b.cleaned,b.confidence,b.source);
    if(bp!==ap) return bp-ap;
    return b.confidence-a.confidence;
  });

  const winner=candidates[0];
  winner.candidates=candidates.slice(0,6).map(c=>({
    source:c.source,
    variant:c.variant,
    cleaned:c.cleaned,
    confidence:c.confidence,
    score:usernamePlausibility(c.cleaned,c.confidence,c.source)
  }));
  return winner;
}

function findLevelShield(img){
  // Connected-component search for the near-square cyan/blue level shield in
  // the upper-left. The XP bar is wide, so aspect-ratio filtering removes it.
  const maxW=700;
  const scale=Math.min(1,maxW/img.naturalWidth);
  const w=Math.round(img.naturalWidth*scale);
  const h=Math.round(img.naturalHeight*scale);

  const c=document.createElement("canvas");
  c.width=w;c.height=h;
  const ctx=c.getContext("2d",{willReadFrequently:true});
  ctx.drawImage(img,0,0,w,h);
  const d=ctx.getImageData(0,0,w,h).data;

  const searchW=Math.floor(w*.23);
  const searchH=Math.floor(h*.18);
  const step=2;
  const gw=Math.ceil(searchW/step);
  const gh=Math.ceil(searchH/step);
  const mask=new Uint8Array(gw*gh);

  for(let gy=0;gy<gh;gy++){
    const y=gy*step;
    for(let gx=0;gx<gw;gx++){
      const x=gx*step;
      const i=(y*w+x)*4;
      const hsv=rgbToHsv(d[i],d[i+1],d[i+2]);
      if(hsv.h>=178&&hsv.h<=220&&hsv.s>.48&&hsv.v>.30){
        mask[gy*gw+gx]=1;
      }
    }
  }

  const seen=new Uint8Array(mask.length);
  let best=null;

  for(let i=0;i<mask.length;i++){
    if(!mask[i]||seen[i]) continue;

    const q=[i];seen[i]=1;
    let minX=1e9,minY=1e9,maxX=-1,maxY=-1,count=0;

    while(q.length){
      const idx=q.pop();
      const gy=Math.floor(idx/gw), gx=idx%gw;
      minX=Math.min(minX,gx);maxX=Math.max(maxX,gx);
      minY=Math.min(minY,gy);maxY=Math.max(maxY,gy);
      count++;

      for(const [dx,dy] of [[1,0],[-1,0],[0,1],[0,-1]]){
        const nx=gx+dx,ny=gy+dy;
        if(nx<0||ny<0||nx>=gw||ny>=gh) continue;
        const ni=ny*gw+nx;
        if(mask[ni]&&!seen[ni]){seen[ni]=1;q.push(ni);}
      }
    }

    const bw=(maxX-minX+1)*step;
    const bh=(maxY-minY+1)*step;
    const aspect=bw/Math.max(bh,1);

    // Near-square shield, large enough to not be an icon speck.
    if(count>45 && aspect>.55 && aspect<1.55 && bw>w*.025 && bh>h*.035){
      if(!best || count>best.count){
        best={x:minX*step,y:minY*step,w:bw,h:bh,count};
      }
    }
  }

  if(!best) return null;

  return {
    x:best.x/scale,
    y:best.y/scale,
    w:best.w/scale,
    h:best.h/scale
  };
}

function cropUsername(img,box,variant){
  const source=imageCanvas(img);
  const scale=5;
  const c=document.createElement("canvas");
  c.width=Math.max(1,Math.round(box.w*scale));
  c.height=Math.max(1,Math.round(box.h*scale));
  const ctx=c.getContext("2d",{willReadFrequently:true});
  ctx.imageSmoothingEnabled=true;
  ctx.drawImage(source,box.x,box.y,box.w,box.h,0,0,c.width,c.height);

  if(variant==="raw") return c;

  const id=ctx.getImageData(0,0,c.width,c.height);
  for(let i=0;i<id.data.length;i+=4){
    const r=id.data[i],g=id.data[i+1],b=id.data[i+2];
    const lum=.299*r+.587*g+.114*b;
    const max=Math.max(r,g,b),min=Math.min(r,g,b);
    const sat=max===0?0:(max-min)/max;
    let v=lum;

    if(variant==="gray") v=lum;
    else if(variant==="bright-text") v=(lum>95&&sat<.55)?255:0;
    else if(variant==="threshold") v=lum>118?255:0;
    else if(variant==="shadow-text"){
      // Clash usernames are light gray/white with a dark outline. Keep neutral
      // mid/high-luminance pixels and suppress colorful village/background pixels.
      v=(lum>72 && sat<.42)?255:0;
    }

    id.data[i]=id.data[i+1]=id.data[i+2]=v;
  }
  ctx.putImageData(id,0,0);
  return c;
}

function cleanUsername(value){
  let s=String(value||"")
    .replace(/\r?\n/g," ")
    .replace(/\s+/g," ")
    .trim();

  s=s.replace(/^\d{2,3}\s+/,"");
  s=s.replace(/[^A-Za-z0-9 _.'’#\-]/g,"").trim();
  s=s.replace(/^[._'’#\- ]+/,"").replace(/[._'’#\- ]+$/,"");

  // Repeated cross-device OCR artifact seen after otherwise-correct names.
  s=s.replace(/\s+em$/i,"");

  return s.slice(0,80);
}

function usernamePlausibility(value,confidence,source=""){
  const s=String(value||"");
  let score=confidence;
  if(s.length>=3) score+=10;
  if(s.length>=5) score+=15;
  if(/[A-Za-z]/.test(s)) score+=12;

  // A Clash player name is normally one compact token. Strongly reward that
  // form; it prevents noisy multi-word OCR from beating a slightly lower
  // confidence but much cleaner focused-band result.
  if(/^[A-Za-z0-9._'#-]{4,20}$/.test(s)) score+=38;
  if(!/\s/.test(s) && s.length>=4 && s.length<=20) score+=14;

  if(source.startsWith("name-band-")) score+=12;
  if(source==="shield" || source==="shield-wide") score+=7;

  const spaces=(s.match(/ /g)||[]).length;
  if(spaces===1) score-=8;
  if(spaces>=2) score-=35;
  if(s.length>22) score-=25;
  return score;
}

function usernameMatchKey(value){
  return String(value||"")
    .toLowerCase()
    .replace(/[^a-z0-9]/g,"");
}

function usernameConfusionKey(value){
  return usernameMatchKey(value)
    .replace(/[o0]/g,"0")
    .replace(/[il1]/g,"1");
}

function editDistance(a,b){
  a=String(a||"");
  b=String(b||"");

  if(a===b) return 0;
  if(!a.length) return b.length;
  if(!b.length) return a.length;

  let prev=Array.from({length:b.length+1},(_,i)=>i);
  let curr=new Array(b.length+1);

  for(let i=1;i<=a.length;i++){
    curr[0]=i;
    for(let j=1;j<=b.length;j++){
      const cost=a[i-1]===b[j-1]?0:1;
      curr[j]=Math.min(
        curr[j-1]+1,
        prev[j]+1,
        prev[j-1]+cost
      );
    }
    [prev,curr]=[curr,prev];
  }

  return prev[b.length];
}

function reconcileUsernameWithKnownPlayers(ocrName){
  const original=normalizeDetectedUsername(ocrName);
  if(!original){
    return {name:"",reason:"empty",distance:null};
  }

  const known=Array.isArray(window.CLASH_KNOWN_PLAYERS)
    ? window.CLASH_KNOWN_PLAYERS.filter(Boolean)
    : [];

  if(!known.length){
    return {name:original,reason:"ocr-only",distance:null};
  }

  const key=usernameMatchKey(original);
  if(!key){
    return {name:original,reason:"ocr-only",distance:null};
  }

  // Exact match after ignoring spaces/punctuation is always safe.
  const exact=known.filter(name=>usernameMatchKey(name)===key);
  if(exact.length===1){
    return {
      name:String(exact[0]),
      reason:"database-exact-normalized",
      distance:0
    };
  }

  const confusionKey=usernameConfusionKey(original);
  const confusionExact=known.filter(
    name=>usernameConfusionKey(name)===confusionKey
  );
  if(confusionExact.length===1){
    return {
      name:String(confusionExact[0]),
      reason:"database-ocr-confusion",
      distance:0
    };
  }

  const scored=known
    .map(name=>({
      name:String(name),
      distance:Math.min(
        editDistance(key,usernameMatchKey(name)),
        editDistance(confusionKey,usernameConfusionKey(name))
      )
    }))
    .sort((a,b)=>a.distance-b.distance || a.name.localeCompare(b.name));

  if(!scored.length){
    return {name:original,reason:"ocr-only",distance:null};
  }

  const best=scored[0];
  const second=scored[1] || null;

  // Conservative threshold:
  //  - names 4-7 chars: at most one edit
  //  - names 8+ chars: at most two edits
  // Also require the best match to beat the runner-up by at least one edit.
  const allowed=key.length>=8 ? 2 : 1;
  const uniqueEnough=!second || second.distance>=best.distance+1;

  const bestKey=usernameMatchKey(best.name);
  let commonSuffix=0;
  while(
    commonSuffix<key.length && commonSuffix<bestKey.length &&
    key[key.length-1-commonSuffix]===bestKey[bestKey.length-1-commonSuffix]
  ) commonSuffix++;

  const longSuffixMatch=
    key.length>=9 && bestKey.length>=9 &&
    best.distance<=4 && commonSuffix>=6 && uniqueEnough;

  if((best.distance<=allowed && uniqueEnough) || longSuffixMatch){
    return {
      name:best.name,
      reason:longSuffixMatch?"database-fuzzy-long-suffix":"database-fuzzy",
      distance:best.distance
    };
  }

  return {
    name:original,
    reason:"ocr-unmatched",
    distance:best.distance
  };
}


/* ---------------- Human-approved learning ---------------- */

function getLearnedGlyphs(){
  try{
    const parsed=JSON.parse(localStorage.getItem(LEARNING_STORAGE_KEY)||"");
    if(parsed && parsed.schema===1 && Array.isArray(parsed.examples)){
      return parsed;
    }
  }catch(e){}
  return {
    schema:1,
    scanner_version:SCANNER_VERSION,
    updated_at:null,
    examples:[]
  };
}

function saveLearnedGlyphs(store){
  store.schema=1;
  store.scanner_version=SCANNER_VERSION;
  store.updated_at=new Date().toISOString();
  localStorage.setItem(LEARNING_STORAGE_KEY,JSON.stringify(store));
  updateLearningStatus();
}

function learnFromReviewedRows(){
  if(!learningConsent?.checked){
    alert("Check the learning consent box before teaching selected examples.");
    return;
  }

  const store=getLearnedGlyphs();
  let added=0;
  let skipped=0;

  for(const [id,item] of currentLearningRows){
    const checkbox=document.querySelector(`input[data-learn-id="${cssEscape(id)}"]`);
    const qtyInput=document.querySelector(`input[data-learning-qty-id="${cssEscape(id)}"]`);

    if(!checkbox?.checked || !qtyInput) continue;

    const qty=Number(qtyInput.value);
    if(!Number.isInteger(qty)||qty<2||qty>99){
      skipped++;
      continue;
    }

    const duplicate=store.examples.some(ex=>
      Number(ex.qty)===qty &&
      ex.glyph_data_url===item.glyph_data_url
    );

    if(duplicate){
      skipped++;
      continue;
    }

    store.examples.push({
      id:`${Date.now()}-${Math.random().toString(36).slice(2,9)}`,
      qty,
      glyph_data_url:item.glyph_data_url,
      card_id:item.card_id,
      card_name:item.name,
      category:item.category,
      source_page:item.page,
      source_slot:item.slot,
      predicted_qty:item.predicted_qty,
      predicted_confidence:item.confidence,
      learned_at:new Date().toISOString(),
      scanner_version:SCANNER_VERSION
    });
    added++;
  }

  // Keep a bounded, balanced library.
  const grouped={};
  for(const ex of store.examples){
    (grouped[ex.qty] ||= []).push(ex);
  }

  store.examples=Object.values(grouped).flatMap(list=>
    list
      .sort((a,b)=>String(b.learned_at).localeCompare(String(a.learned_at)))
      .slice(0,MAX_LEARNED_PER_QTY)
  );

  saveLearnedGlyphs(store);

  if(learningStatus && (added||skipped)){
    learningStatus.textContent=
      `Learned ${added} badge example${added===1?"":"s"} in this browser`+
      (skipped?` (${skipped} skipped).`:"." )+
      " No inventory was saved.";
  }
}

function exportLearnedGlyphs(){
  const store=getLearnedGlyphs();

  if(!store.examples.length){
    alert("No learned badge examples are stored in this browser yet.");
    return;
  }

  const payload={
    ...store,
    exported_at:new Date().toISOString(),
    export_build:SCANNER_BUILD_ID
  };

  const blob=new Blob(
    [JSON.stringify(payload,null,2)],
    {type:"application/json"}
  );

  const url=URL.createObjectURL(blob);
  const a=document.createElement("a");
  a.href=url;
  a.download=`clash-cards-learned-glyphs-${new Date().toISOString().slice(0,10)}.json`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),500);
}

function updateLearningStatus(){
  if(!learningStatus) return;

  const store=getLearnedGlyphs();
  const counts={};
  for(const ex of store.examples){
    counts[ex.qty]=(counts[ex.qty]||0)+1;
  }

  const details=Object.keys(counts)
    .sort((a,b)=>Number(a)-Number(b))
    .map(q=>`x${q}: ${counts[q]}`)
    .join(", ");

  learningStatus.textContent=store.examples.length
    ? `${store.examples.length} learned badge example${store.examples.length===1?"":"s"} stored locally${details?` (${details})`:""}.`
    : "No learned badge examples stored in this browser yet.";
}

function cssEscape(value){
  if(window.CSS && typeof CSS.escape==="function") return CSS.escape(value);
  return String(value).replace(/["\\]/g,"\\$&");
}


async function fetchPreviousInventory(playerName){
  const name=String(playerName||"").trim();
  if(!name) return {found:false,display_name:null,quantities:{}};

  try{
    const url=new URL(window.location.href);
    url.search="";
    url.hash="";
    url.searchParams.set("ajax_player_inventory",name);

    const response=await fetch(url.toString(),{
      method:"GET",
      credentials:"same-origin",
      headers:{"Accept":"application/json"},
      cache:"no-store"
    });

    if(!response.ok) throw new Error(`HTTP ${response.status}`);
    const data=await response.json();

    return {
      found:!!data.found,
      display_name:data.display_name||null,
      quantities:data.quantities&&typeof data.quantities==="object"
        ? data.quantities
        : {}
    };
  }catch(e){
    console.warn("Could not load previous player inventory",e);
    return {found:false,display_name:null,quantities:{}};
  }
}

function addMissingPageRows(results,pages,previousInventory,debug){
  const quantities=previousInventory?.quantities||{};

  for(let page=0;page<5;page++){
    if(pages.has(page)) continue;

    for(let slot=0;slot<12;slot++){
      const orderIndex=page*12+slot;
      if(orderIndex>=EVENT_ORDER.length) continue;

      const [category,name]=EVENT_ORDER[orderIndex];
      const dbCard=byKey.get(`${category}|${name}`);
      if(!dbCard) continue;

      const key=String(dbCard.id);
      const hasPrevious=Object.prototype.hasOwnProperty.call(quantities,key);
      const qty=hasPrevious ? Math.max(0,Number(quantities[key])||0) : 0;

      results.push({
        card_id:Number(dbCard.id),
        name,
        category,
        owned_qty:qty,
        confidence:hasPrevious?"previous":"manual",
        learning_id:`missing-${page}-${slot}-${dbCard.id}`,
        learnable:false,
        fallback:true,
        fallback_source:hasPrevious?"previous":"zero"
      });

      debug.push(
        `FILL | page=${page+1} | slot=${slot+1} | ${category} | ${name} | qty=${qty} | source=${hasPrevious?"previous":"zero"}`
      );
    }
  }
}

/* ---------------- UI / utilities ---------------- */

function render(rows){
  body.innerHTML="";
  rows.sort((a,b)=>
    EVENT_ORDER.findIndex(x=>x[0]===a.category&&x[1]===a.name)-
    EVENT_ORDER.findIndex(x=>x[0]===b.category&&x[1]===b.name)
  );

  for(const r of rows){
    const tr=document.createElement("tr");
    if(r.fallback) tr.classList.add("scan-fallback-row");

    const learnCell=r.learnable
      ? `<label class="learn-example">
           <input type="checkbox" data-learn-id="${esc(r.learning_id)}">
           use
         </label>`
      : `<span class="muted">—</span>`;

    tr.innerHTML=`<td>${esc(r.name)}</td><td>${esc(r.category)}</td>
      <td><input type="number" min="0" max="99"
          name="detected[${r.card_id}]"
          value="${r.owned_qty}"
          data-learning-qty-id="${esc(r.learning_id)}"
          required></td>
      <td><span class="confidence confidence-${r.confidence}">${
        r.confidence==="previous" ? "Previous" :
        r.confidence==="manual" ? "Enter" :
        cap(r.confidence)
      }</span></td>
      <td>${learnCell}</td>`;

    body.appendChild(tr);
  }

  review.hidden=rows.length===0;

  if(saveDetectedButton && rows.length===0){
    saveDetectedButton.disabled=true;
  }

  if(teachSelected){
    teachSelected.disabled=!rows.some(r=>r.learnable);
  }
}

function geometrySummary(g){
  const b=g.boxes[0];
  return `method=${g.gridMethod||"unknown"},panel=${Math.round(g.panelLeft)}-${Math.round(g.panelRight)},row1=${g.row1.map(Math.round).join("-")},row2=${g.row2.map(Math.round).join("-")},card=${Math.round(b.w)}x${Math.round(b.h)}`;
}

function imageCanvas(img){
  if(img._canvas) return img._canvas;
  const c=document.createElement("canvas");
  c.width=img.naturalWidth;c.height=img.naturalHeight;
  c.getContext("2d").drawImage(img,0,0);
  img._canvas=c;
  return c;
}

function loadImage(file){
  return new Promise((resolve,reject)=>{
    const img=new Image(),url=URL.createObjectURL(file);
    img.onload=()=>{URL.revokeObjectURL(url);resolve(img);};
    img.onerror=()=>{URL.revokeObjectURL(url);reject(new Error("Could not read "+file.name));};
    img.src=url;
  });
}

function loadImageUrl(url){
  return new Promise((resolve,reject)=>{
    const img=new Image();
    img.onload=()=>resolve(img);
    img.onerror=()=>reject(new Error("Could not load badge template: "+url));
    img.src=url;
  });
}

function smoothArray(values,radius){
  const out=new Float32Array(values.length);
  let sum=0,left=0,right=0;
  for(let i=0;i<values.length;i++){
    while(right<values.length && right<=i+radius){sum+=values[right++];}
    while(left<i-radius){sum-=values[left++];}
    out[i]=sum/Math.max(right-left,1);
  }
  return out;
}

function findIntervals(values,pred,minLen){
  const out=[];
  let start=null;
  for(let i=0;i<values.length;i++){
    const yes=pred(values[i]);
    if(yes&&start===null) start=i;
    if((!yes||i===values.length-1)&&start!==null){
      const end=yes&&i===values.length-1?i+1:i;
      if(end-start>=minLen) out.push([start,end]);
      start=null;
    }
  }
  return out;
}

function longestTrueRun(values){
  let best=null,start=null;
  for(let i=0;i<values.length;i++){
    if(values[i]&&start===null) start=i;
    if((!values[i]||i===values.length-1)&&start!==null){
      const end=values[i]&&i===values.length-1?i+1:i;
      if(!best||end-start>best[1]-best[0]) best=[start,end];
      start=null;
    }
  }
  return best;
}

function isCategoryBorderRgb(r,g,b){
  const hsv=rgbToHsv(r,g,b);
  const h=hsv.h;
  const categoryHue=
    (h>=285&&h<=340) ||
    (h>=255&&h<285) ||
    (h>=180&&h<=225) ||
    (h<=30||h>=345);
  return categoryHue&&hsv.s>.45&&hsv.v>.40;
}

function isPanelBeigeRgb(r,g,b){
  const hsv=rgbToHsv(r,g,b);
  return hsv.h>=18&&hsv.h<=48&&hsv.s>=.10&&hsv.s<=.48&&hsv.v>=.50&&hsv.v<=.94;
}

function rgbToHsv(r,g,b){
  r/=255;g/=255;b/=255;
  const max=Math.max(r,g,b),min=Math.min(r,g,b),d=max-min;
  let h=0;
  if(d){
    if(max===r) h=60*(((g-b)/d)%6);
    else if(max===g) h=60*((b-r)/d+2);
    else h=60*((r-g)/d+4);
  }
  if(h<0) h+=360;
  return {h,s:max?d/max:0,v:max};
}

function hueCategory(h){
  if(h>=290&&h<340) return "E";
  if(h>=255&&h<290) return "D";
  if(h>=180&&h<=225) return "B";
  if(h<35||h>=340) return "S";
  return "?";
}

function vectorDistance(a,b){
  const n=Math.min(a.length,b.length);
  if(!n) return 1;
  let diff=0;
  for(let i=0;i<n;i++) if(a[i]!==b[i]) diff++;
  return diff/n;
}

function setStatus(t,p){
  status.textContent=t;
  progressWrap.hidden=false;
  progress.value=Math.max(0,Math.min(100,p||0));
}

function esc(v){
  return String(v)
    .replace(/&/g,"&amp;")
    .replace(/</g,"&lt;")
    .replace(/>/g,"&gt;")
    .replace(/"/g,"&quot;")
    .replace(/'/g,"&#039;");
}
function cap(v){return String(v).charAt(0).toUpperCase()+String(v).slice(1);}
})();