# Prompt: تحويل Modal Bootstrap إلى Card على الموبايل

## المشكلة

في تطبيق PHP يستخدم Bootstrap 5، بعض Modals لا تعمل بشكل جيد على الهواتف المحمولة. المطلوب: استخدام **Card** على الموبايل بدلاً من **Modal**، مع الاحتفاظ بـ **Modal** على الكمبيوتر.

## الحل المطلوب

إنشاء نظام مزدوج:
- على الموبايل (عرض ≤ 768px): استخدام **Card** (Bootstrap Card)
- على الكمبيوتر (عرض > 768px): استخدام **Modal** (Bootstrap Modal)

## الخطوات التفصيلية

### الخطوة 1: تعديل Modal الموجود (للكمبيوتر فقط)

ابحث عن Modal الحالي وأضف `d-none d-md-block`:

```html
<!-- قبل التعديل -->
<div class="modal fade" id="myModal" tabindex="-1">
    ...
</div>

<!-- بعد التعديل -->
<div class="modal fade d-none d-md-block" id="myModal" tabindex="-1">
    ...
</div>
```

### الخطوة 2: إنشاء Card جديد (للموبايل فقط)

أنشئ Card جديد بنفس محتوى Modal، مع:
- `d-md-none` لإخفائه على الكمبيوتر
- `style="display: none;"` لإخفائه افتراضياً
- IDs مختلفة للعناصر لتجنب التعارض

```html
<!-- Card للموبايل - إضافة/إنشاء -->
<div class="card shadow-sm mb-4 d-md-none" id="myCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-plus-circle me-2"></i>عنوان النموذج
        </h5>
    </div>
    <div class="card-body">
        <form method="POST">
            <!-- نفس حقول Modal هنا -->
            <!-- لكن مع IDs مختلفة إذا لزم الأمر -->
            
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="closeMyCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>
```

### الخطوة 3: إنشاء/تعديل دالة JavaScript للفتح

أنشئ دالة تتحقق من نوع الجهاز وتفتح النموذج المناسب:

```javascript
// دالة التحقق من الموبايل
function isMobile() {
    return window.innerWidth <= 768;
}

// دالة التمرير التلقائي
function scrollToElement(element) {
    if (!element) return;
    
    setTimeout(function() {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const elementTop = rect.top + scrollTop;
        const offset = 80;
        
        requestAnimationFrame(function() {
            window.scrollTo({
                top: Math.max(0, elementTop - offset),
                behavior: 'smooth'
            });
        });
    }, 200);
}

// دالة فتح النموذج
function showMyModal() {
    closeAllForms(); // إغلاق أي نماذج مفتوحة
    
    if (isMobile()) {
        // على الموبايل: استخدام Card
        const card = document.getElementById('myCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        // على الكمبيوتر: استخدام Modal
        const modal = document.getElementById('myModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}
```

### الخطوة 4: إنشاء دالة إغلاق Card

```javascript
function closeMyCard() {
    const card = document.getElementById('myCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}
```

### الخطوة 5: تحديث دالة closeAllForms

أضف Card الجديد إلى قائمة Cards التي يتم إغلاقها:

```javascript
function closeAllForms() {
    // إغلاق جميع Cards على الموبايل
    const cards = ['myCard', 'otherCard1', 'otherCard2'];
    cards.forEach(function(cardId) {
        const card = document.getElementById(cardId);
        if (card && card.style.display !== 'none') {
            card.style.display = 'none';
            const form = card.querySelector('form');
            if (form) form.reset();
        }
    });
    
    // إغلاق جميع Modals على الكمبيوتر
    const modals = ['myModal', 'otherModal1', 'otherModal2'];
    modals.forEach(function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) modalInstance.hide();
        }
    });
}
```

### الخطوة 6: تحديث زر الفتح

غيّر زر الفتح ليستدعي الدالة الجديدة:

```html
<!-- قبل التعديل -->
<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#myModal">
    إضافة جديد
</button>

<!-- بعد التعديل -->
<button class="btn btn-primary" onclick="showMyModal()">
    إضافة جديد
</button>
```

## نقاط مهمة

### 1. IDs مختلفة

إذا كان Modal يحتوي على عناصر ديناميكية (مثل JavaScript)، استخدم IDs مختلفة في Card:
- Modal: `id="myInput"`
- Card: `id="myCardInput"`

### 2. حقول مخفية (Hidden Fields)

إذا كان Modal يحتوي على حقول مخفية يتم تحديثها عبر JavaScript، أضف نفس الحقول في Card.

### 3. Event Listeners

إذا كان Modal يحتوي على event listeners (مثل `change` على select)، أضف نفس الـ listeners للعناصر في Card.

### 4. JavaScript الديناميكي

إذا كان Modal يحتوي على JavaScript يضيف/يحذف عناصر ديناميكياً:
- أنشئ دالة مشتركة يمكن استخدامها من كلا النموذجين
- أو أنشئ نسخة من الدالة للـ Card مع IDs مختلفة

### 5. Forms Validation

إذا كان Modal يحتوي على validation، أضف نفس الـ validation للـ Card.

### 6. Bootstrap Classes

- Modal للكمبيوتر: `d-none d-md-block`
- Card للموبايل: `d-md-none`

## مثال كامل

```html
<!-- Modal للكمبيوتر فقط -->
<div class="modal fade d-none d-md-block" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">إضافة عنصر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="addItemForm">
                <div class="modal-body">
                    <input type="text" class="form-control" name="name" id="modalName" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Card للموبايل فقط -->
<div class="card shadow-sm mb-4 d-md-none" id="addItemCard" style="display: none;">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">إضافة عنصر</h5>
    </div>
    <div class="card-body">
        <form method="POST" id="addItemCardForm">
            <input type="text" class="form-control" name="name" id="cardName" required>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">حفظ</button>
                <button type="button" class="btn btn-secondary" onclick="closeAddItemCard()">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddItemModal() {
    closeAllForms();
    
    if (isMobile()) {
        const card = document.getElementById('addItemCard');
        if (card) {
            card.style.display = 'block';
            setTimeout(function() {
                scrollToElement(card);
            }, 50);
        }
    } else {
        const modal = document.getElementById('addItemModal');
        if (modal) {
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        }
    }
}

function closeAddItemCard() {
    const card = document.getElementById('addItemCard');
    if (card) {
        card.style.display = 'none';
        const form = card.querySelector('form');
        if (form) form.reset();
    }
}
</script>
```

## التحقق من النجاح

1. ✅ على الموبايل: يفتح Card بدلاً من Modal
2. ✅ على الكمبيوتر: يفتح Modal كالمعتاد
3. ✅ التمرير التلقائي: يعمل على الموبايل
4. ✅ إعادة تعيين النموذج: تعمل عند الإغلاق
5. ✅ لا توجد أخطاء في Console

---

**ملاحظة:** استخدم هذا الـ prompt مع أي AI model آخر لحل مشاكل مشابهة.
