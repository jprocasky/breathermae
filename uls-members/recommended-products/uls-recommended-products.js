(function ($) {
    'use strict';

    function RecPicker($wrap) {
        this.wrap = $wrap;
        this.cat = $wrap.data('category');
        this.perPage = parseInt($wrap.data('per-page'), 10);
        this.thumbMax = parseInt($wrap.data('thumb-max'), 10) || 32;
        this.page = 1;
        this.email = '';
        this.selected = {};
        this.productIndex = {};

        this.$picker = $wrap.find('.uls-rec-picker');
        this.$selected = $wrap.find('.uls-rec-selected');

        this.init();
    }

    RecPicker.prototype.init = function () {
        const self = this;

        document.addEventListener('uls:selected-member', function (e) {
            self.email = e.detail.email;
            self.selected = {};
            self.productIndex = {};
            self.page = 1;
            //self.loadPage();
            self.loadAssigned();
            
            refreshSelectedShortcodes(self.email);
        });
    };

    RecPicker.prototype.loadAssigned = function () {
        const self = this;

        $.post(ULS_REC_PRODUCTS.ajaxurl, {
            action: 'uls_get_assigned_products',
            nonce: ULS_REC_PRODUCTS.nonce,
            email: self.email
        }).done(function (r) {
            if (!r.success) return;

            // Populate selected map BEFORE rendering picker
            self.selected = {};
            r.data.forEach(id => {
                self.selected[id] = true;
            });

            // Now safe to render picker with pre-checked boxes
            self.loadPage();

            // Update standalone selected shortcode
            refreshSelectedShortcodes(self.email);
        });
    };


    RecPicker.prototype.loadPage = function () {
        const self = this;

        $.post(ULS_REC_PRODUCTS.ajaxurl, {
            action: 'uls_get_recommendable_products',
            nonce: ULS_REC_PRODUCTS.nonce,
            category: self.cat,
            per_page: self.perPage,
            page: self.page
        }).done(function (r) {
            if (!r.success) return;
            self.renderPicker(r.data.products, r.data.has_more);
        });
    };

    RecPicker.prototype.renderPicker = function (products, hasMore) {
        const self = this;

        let html = '<table class="uls-rec-table"><tbody>';

        products.forEach(p => {
            self.productIndex[p.id] = p;
            const checked = self.selected[p.id] ? 'checked' : '';

            html += `
            <tr data-id="${p.id}">
                <td class="uls-thumb">
                    ${p.img
                        ? `<img src="${p.img}" style="max-width:${self.thumbMax}px; max-height:${self.thumbMax}px;" />`
                        : ''
                    }
                </td>
                <td class="uls-title">
                    <a href="${p.link}" target="_blank" rel="noopener noreferrer">
                        ${p.title}
                    </a>
                </td>
                <td class="uls-check">
                    <input type="checkbox" ${checked}>
                </td>
            </tr>`;
        });

        html += '</tbody></table>';
        html += self.renderPagination(hasMore);

        self.$picker.html(html);

        self.$picker.find('input[type=checkbox]').on('change', function () {
            const id = parseInt($(this).closest('tr').data('id'), 10);
            self.toggle(id, this.checked);
        });

        self.$picker.find('[data-page]').on('click', function (e) {
            e.preventDefault();
            const page = parseInt($(this).data('page'), 10);
            if (!isNaN(page) && page !== self.page) {
                self.page = page;
                self.loadPage();
            }
        });
    };

    function refreshSelectedShortcodes(email) {
        if (!email) return;

        $('.uls-selected-products-wrapper').each(function () {
            const $wrap = $(this);
            const thumbMax = parseInt($wrap.data('thumb-max'), 10) || 32;

            $.post(ULS_REC_PRODUCTS.ajaxurl, {
                action: 'uls_render_selected_recommended_products',
                nonce: ULS_REC_PRODUCTS.nonce,
                email: email,
                thumb_max: thumbMax
            }).done(function (r) {
                if (r.success) {
                    $wrap.html(r.data);
                }
            });
        });
    }
    RecPicker.prototype.renderPagination = function (hasMore) {
        let html = '<div class="uls-rec-pagination">';

        if (this.page > 1) {
            html += `<a href="#" data-page="${this.page - 1}">« Prev</a>`;
        }

        const start = Math.max(1, this.page - 2);
        const end = this.page + (hasMore ? 2 : 0);

        for (let i = start; i <= end; i++) {
            if (i === this.page) {
                html += `<span class="current">${i}</span>`;
            } else {
                html += `<a href="#" data-page="${i}">${i}</a>`;
            }
        }

        if (hasMore) {
            html += `<a href="#" data-page="${this.page + 1}">Next »</a>`;
        }

        html += '</div>';
        return html;
    };

    RecPicker.prototype.toggle = function (id, checked) {
        const self = this;

        // Update picker UI immediately (optimistic)
        if (checked) self.selected[id] = true;
        else delete self.selected[id];

        self.renderSelected();

        // Persist to DB, THEN refresh shortcode
        $.post(ULS_REC_PRODUCTS.ajaxurl, {
            action: 'uls_toggle_recommended_product',
            nonce: ULS_REC_PRODUCTS.nonce,
            email: self.email,
            product_id: id,
            checked: checked ? 1 : 0
        }).done(function () {
            refreshSelectedShortcodes(self.email); // ✅ CORRECT LOCATION
        });
    };

    RecPicker.prototype.renderSelected = function () {
        if (!this.$selected.length) return;

        let html = '<table class="uls-selected-products-table"><tbody>';

        Object.keys(this.selected).forEach(id => {
            const p = this.productIndex[id];
            if (!p) return;

            html += `
            <tr data-id="${id}">
                <td class="uls-thumb">
                    ${p.img
                        ? `<img src="${p.img}" style="max-width:${this.thumbMax}px; max-height:${this.thumbMax}px;" />`
                        : ''
                    }
                </td>
                <td class="uls-title">
                    <a href="${p.link}" target="_blank" rel="noopener noreferrer">
                        ${p.title}
                    </a>
                </td>
            </tr>`;
        });

        html += '</tbody></table>';
        this.$selected.html(html);
    };

    $(function () {
        $('.uls-rec-wrapper').each(function () {
            new RecPicker($(this));
        });
    });

})(jQuery);