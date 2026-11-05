    <script>
        document.getElementById('plan-select')?.addEventListener('change', function (e) {
            var opt = e.target.selectedOptions[0];
            document.getElementById('finished-good-input').value = opt?.dataset.finishedGood || '';
            document.querySelector('[name="target_qty"]').value = opt?.dataset.qty || '';
            document.querySelector('[name="uom"]').value = opt?.dataset.uom || '';
            document.getElementById('site-select').value = opt?.dataset.site || '';
            document.getElementById('site-select').dispatchEvent(new Event('change'));
            var mapping = @json($mappingOptions)
                .find(m => String(m.site_id) === opt?.dataset.site && String(m.finished_good_id) === opt?.dataset.finishedGood);
            if (mapping) {
                for (const key of ['inventory_fg_item_id','rm_warehouse_id','fg_warehouse_id','reusable_pcm_item_id']) {
                    document.querySelector(`[name="${key}"]`).value = mapping[key] || '';
                }
            } else {
                for (const key of ['inventory_fg_item_id','rm_warehouse_id','fg_warehouse_id','reusable_pcm_item_id']) {
                    document.querySelector(`[name="${key}"]`).value = '';
                }
            }
            document.querySelectorAll('#recipe-select option[data-fg], #bom-select option[data-fg]').forEach(function (choice) {
                choice.hidden = choice.dataset.fg !== opt?.dataset.finishedGood;
            });
            document.getElementById('recipe-select').value = '';
            document.getElementById('bom-select').value = '';
            var matchingRecipes = [...document.querySelectorAll('#recipe-select option[data-fg]')]
                .filter(choice => choice.dataset.fg === opt?.dataset.finishedGood);
            var matchingBoms = [...document.querySelectorAll('#bom-select option[data-fg]')]
                .filter(choice => choice.dataset.fg === opt?.dataset.finishedGood);
            if (matchingRecipes.length === 1) document.getElementById('recipe-select').value = matchingRecipes[0].value;
            else if (matchingRecipes.length === 0 && matchingBoms.length === 1) document.getElementById('bom-select').value = matchingBoms[0].value;
        });
        document.getElementById('site-select')?.addEventListener('change', function (e) {
            ['rm-warehouse-select', 'fg-warehouse-select'].forEach(function (id) {
                var select = document.getElementById(id);
                select.value = '';
                var type = id === 'rm-warehouse-select' ? 'Raw Material' : 'Finished Goods';
                select.querySelectorAll('option[data-site]').forEach(function (opt) { opt.hidden = opt.dataset.site !== e.target.value || opt.dataset.type !== type; });
            });
        });
        if (@json(request('plan')) && !@json($errors->any())) {
            document.getElementById('plan-select')?.dispatchEvent(new Event('change'));
        }
    </script>
