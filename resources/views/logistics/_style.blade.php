@include('access-control._style')
<style>
#logistics{max-width:1440px;margin:auto;color:var(--text-primary,#172b28)}
#logistics .lx-head{background:#123e36;color:#fff;padding:28px;border-radius:16px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap}
#logistics .lx-eyebrow{font-size:11px;letter-spacing:.15em;text-transform:uppercase;opacity:.75;font-weight:700}
#logistics h1{font-size:28px;font-weight:750;line-height:1.2;margin:7px 0}#logistics h2{font-size:17px;font-weight:700;margin-bottom:14px}
#logistics .lx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(225px,1fr));gap:16px}
#logistics .lx-card{background:var(--surface-1,#fff);border:1px solid var(--border,#dfe7e2);border-radius:12px;padding:22px;margin-top:18px}
#logistics label.lx-field{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:650}
#logistics :is(input:not([type=checkbox]):not([type=hidden]),select,textarea){border:1px solid var(--border,#cddbd5);border-radius:7px;padding:10px 11px;background:var(--surface-1,#fff);color:var(--text-primary,#172b28);font-size:14px;min-width:0;width:100%;font-weight:400}
#logistics textarea{min-height:85px}#logistics :is(input,select,textarea,button,a):focus-visible{outline:3px solid #8bbcac;outline-offset:2px}
#logistics .lx-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border-radius:7px;font-size:13px;font-weight:650;border:1px solid var(--border,#d5e2dc);background:var(--surface-1,#fff);color:var(--text-primary,#193c32);min-height:42px;text-decoration:none}
#logistics .lx-primary{background:#17634e;color:#fff;border-color:#17634e}#logistics .lx-actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:16px}
#logistics .lx-muted{font-size:12px;color:var(--text-secondary,#677e75);line-height:1.6}#logistics .lx-chip{display:inline-flex;border:1px solid #c9ded4;background:#edf6f1;color:#245442;padding:4px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
#logistics .lx-count{font-size:30px;font-weight:750;line-height:1.25;margin-top:9px}#logistics .lx-table{width:100%;border-collapse:collapse;text-align:left;font-size:13px}#logistics .lx-table th{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary,#64786e);padding:12px 10px;border-bottom:1px solid var(--border,#dfe7e2)}#logistics .lx-table td{padding:13px 10px;vertical-align:top;border-bottom:1px solid var(--border,#e7eeea)}
#logistics .lx-scroll{overflow-x:auto}#logistics .lx-link{color:#17634e;text-decoration:underline;font-weight:600}#logistics .lx-note{border-left:3px solid #74ab94;padding:10px 14px;background:var(--surface-2,#f0f6f2);font-size:13px;line-height:1.6}
#logistics .lx-error{border:1px solid #e7a5a5;background:#fff2f2;color:#832e2e;padding:14px;border-radius:9px;margin-top:15px}#logistics .lx-success{border:1px solid #9fcab6;background:#eef8f2;color:#245a3c;padding:14px;border-radius:9px;margin-top:15px}
#logistics .lx-progress{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}#logistics .lx-step{flex:1;min-width:135px;border-top:4px solid #dae5de;padding:12px 8px;font-size:12px;color:var(--text-secondary,#65766d)}#logistics .lx-step.done{border-color:#218267;color:var(--text-primary,#193f32);font-weight:700}
#logistics .lx-timeline{border-left:2px solid #c7ddd1;padding-left:17px;margin-left:5px}#logistics .lx-event{padding:0 0 22px;position:relative}#logistics .lx-event:before{content:'';width:8px;height:8px;border-radius:50%;background:#2c7a5c;position:absolute;left:-22px;top:5px}
#logistics details summary{cursor:pointer;font-weight:700}#logistics .lx-split{display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:18px}#logistics .lx-money{font-variant-numeric:tabular-nums;font-weight:700}
@media(max-width:850px){#logistics .lx-split{grid-template-columns:1fr}#logistics .lx-head{padding:22px}#logistics h1{font-size:23px}}
</style>
@if(session('status'))<div class="lx-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="lx-error" role="alert"><strong>Please check your submission</strong><ul class="list-disc pl-5 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
