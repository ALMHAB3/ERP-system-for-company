// Checks that every entry of the English phrase list is a [arabic, english] pair (an empty slot breaks the whole interface). Usage: node scripts/check-i18n.cjs public/app/i18n-en.js
const fs=require('fs');const s=fs.readFileSync(process.argv[2],'utf8').replace('export const EN_PAIRS =','module.exports =');
const m={exports:{}};new Function('module',s)(m);const a=m.exports;let bad=0;
for(let i=0;i<a.length;i++){const e=a[i];if(!Array.isArray(e)||e.length!==2||typeof e[0]!=='string'||typeof e[1]!=='string'){bad++;console.log("BAD index",i,JSON.stringify(e),"near:",JSON.stringify(a[i-1]));}}
console.log(a.length,'entries,',bad,'bad');process.exit(bad?1:0);
