-- Migration: إضافة Foreign Key Constraints لضمان سلامة بيانات العملاء والفواتير
-- تاريخ الإنشاء: 2024
-- الوصف: يضيف قيود Foreign Key على invoices و customer_purchase_history لمنع تضارب البيانات

-- إضافة Foreign Key على invoices.customer_id
-- هذا يضمن أن كل فاتورة مرتبطة بعميل موجود فعلياً
ALTER TABLE invoices 
ADD CONSTRAINT fk_invoices_customer 
FOREIGN KEY (customer_id) REFERENCES customers(id) 
ON DELETE RESTRICT;

-- إضافة Foreign Key على customer_purchase_history.customer_id
-- هذا يضمن أن كل سجل مشتريات مرتبط بعميل موجود فعلياً
ALTER TABLE customer_purchase_history 
ADD CONSTRAINT fk_cph_customer 
FOREIGN KEY (customer_id) REFERENCES customers(id) 
ON DELETE CASCADE;

-- إضافة Foreign Key على customer_purchase_history.invoice_id
-- هذا يضمن أن كل سجل مشتريات مرتبط بفاتورة موجودة فعلياً
ALTER TABLE customer_purchase_history 
ADD CONSTRAINT fk_cph_invoice 
FOREIGN KEY (invoice_id) REFERENCES invoices(id) 
ON DELETE CASCADE;

