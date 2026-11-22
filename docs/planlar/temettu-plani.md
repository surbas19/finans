# Temettü planı (2026-11-22)

İstek: temettü için önceden tarih girilebilsin; zamanı yaklaşınca ya da temettü sayfasına girince hatırlatıcı; senet, lot, temettü oranı ve yatacak para görünsün. Seçimler (artifact'tan): **A** Portföy'de "Temettü" sekmesi, **D1** kart (yatacak para büyük), **E1** Özet'in tepesinde şerit (3 gün kala, günü geçince "yattı mı?"), **F1** hisse başı brüt TL ile giriş.

Yapılanlar: arka uç (`temettu_planlari`, `TemettuPlanlari`, `TemettuPlaniController`, rotalar, 10 test), ön yüz (`TemettuView`, `TemettuKarti`, `TemettuPlanFormu`, `TemettuYattiDialog`, `TemettuSeridi`, sekme + rota, 7 test), Edge e2e (plan → şerit → yattı → hesap +net, temizlik).
Karar: lot plana yazılmaz, ödeme gününde hesaptaki adettir; stopaj varsayılanı %10 (formda değiştirilir).
