<?php

namespace App\Support;

class AiIntentClassifier
{
    /**
     * The intent categories the AI Assistant recognizes across every role.
     * Anything that matches none of these -- general trivia, politics,
     * sports, programming, homework, etc. -- classifies as Unknown and gets
     * a polite refusal instead of a fabricated answer. "Marketplace Data"
     * and "Recommendations" are not scripted topics here: they're resolved
     * against the live database by AiDataQueryResolver/AiRecommendationEngine
     * before classify() ever runs (see GeminiService::answer()).
     */
    public const CATEGORIES = [
        'Greeting',
        'Account',
        'Marketplace',
        'Listings',
        'Orders',
        'Seller Earnings',
        'Withdrawals',
        'Payments',
        'Delivery',
        'Reviews',
        'Messaging',
        'Seller Information',
        'Notifications',
        'Reports',
        'Analytics',
        'Fish Care',
        'Municipality',
        'Unknown',
    ];

    /**
     * Simple greeting words/phrases, matched with word boundaries so a word
     * like "fish" (which contains the substring "hi") is never mistaken for
     * a greeting.
     */
    private const GREETING_PATTERN = '/\b(hi|hello|hey|kumusta|kamusta|musta|maayo)\b/i';

    private const GREETING_SUBSTRINGS = ['good morning', 'good afternoon', 'good evening'];

    /**
     * Every recognized topic, tagged with the coarse category it belongs to.
     * This is the single source of truth for both naming the category
     * (classify()) and picking the exact scripted answer/knowledge-base fact
     * (GeminiService's grounding context and offline fallback).
     *
     * Each entry's top-level English/Tagalog/Bisaya text is the default,
     * role-agnostic explanation. An optional 'roles' map overrides that text
     * for a specific authenticated role -- e.g. "wallet" means something
     * different to a Buyer (doesn't have one) than a Seller (their earnings
     * dashboard). Roles without an override fall back to the default text.
     * See AiIntentClassifier::topicContext()/topicFallback().
     */
    public const TOPICS = [
        [
            'category' => 'Marketplace',
            'keywords' => [
                'buy', 'purchase', 'how to order', 'marketplace', 'browse', 'listing', 'filter',
                'how do i order', 'how can i order', 'place an order', 'placing an order',
                'how do i checkout', 'how to checkout', 'how do i purchase', 'how to purchase',
            ],
            'English' => 'To buy fingerlings: open a listing from Browse or Marketplace, pick a quantity, and tap "Pay with PayMongo" to check out securely. Your order status updates automatically as the seller confirms, ships, and completes delivery -- track it anytime from your Orders tab.',
            'Tagalog' => 'Para bumili ng fingerlings: buksan ang isang listing mula sa Browse o Marketplace, pumili ng dami, at pindutin ang "Pay with PayMongo" para sa secure na checkout. Awtomatikong nag-uupdate ang status ng order habang kinukumpirma, ipinapadala, at kino-complete ito ng seller -- subaybayan ito sa iyong Orders tab.',
            'Bisaya' => 'Para mopalit og fingerlings: ablihi ang usa ka listing gikan sa Browse o Marketplace, pilia ang kantidad, ug i-tap ang "Pay with PayMongo" para sa secure nga checkout. Awtomatik nga nag-update ang status sa order samtang gikumpirma, gipadala, ug gikompleto kini sa seller -- subaya kini sa imong Orders tab.',
            'roles' => [
                'seller' => [
                    'English' => 'Buyers order directly from your listings: they pick a quantity and pay via PayMongo, which creates an order against your inventory automatically. You don\'t place orders yourself -- your job is keeping listings accurate and updating order status (confirmed -> in-transit -> completed) as you fulfill each one from your Orders tab.',
                ],
                'lgu_admin' => [
                    'English' => 'Ordering happens between Buyers and Sellers directly -- Buyers pay through PayMongo checkout on an approved listing. As an LGU Admin you don\'t place or fulfill orders; your role is approving the listings and seller earnings that make those orders possible in your municipality.',
                ],
                'super_admin' => [
                    'English' => 'Ordering happens between Buyers and Sellers directly -- Buyers pay through PayMongo checkout on an approved listing, and the order updates automatically as the seller fulfills it. As Super Admin you can review every order platform-wide, but you don\'t place orders yourself.',
                ],
            ],
        ],
        [
            'category' => 'Marketplace',
            'keywords' => ['wallet', 'available balance', 'pending balance', 'processing amount'],
            'English' => 'The Wallet is a Seller feature for tracking earnings -- Pending Balance (payment captured, awaiting delivery + LGU approval), Available Balance (ready to withdraw), and Withdrawn Amount. As a Buyer, your payments go through PayMongo checkout directly; you don\'t need a wallet to purchase.',
            'Tagalog' => 'Ang Wallet ay feature ng Seller para subaybayan ang kita -- Pending Balance (nakuha na ang bayad, hinihintay ang delivery + pag-apruba ng LGU), Available Balance (pwede nang i-withdraw), at Withdrawn Amount. Bilang Buyer, direktang dumadaan ang bayad mo sa PayMongo checkout; hindi mo kailangan ng wallet para bumili.',
            'Bisaya' => 'Ang Wallet usa ka feature sa Seller para subayon ang kita -- Pending Balance (nakuha na ang bayad, ga-hulat sa delivery + pag-aprubar sa LGU), Available Balance (pwede na i-withdraw), ug Withdrawn Amount. Isip Buyer, direkta nga moagi ang imong bayad sa PayMongo checkout; wala nimo kinahanglana ang wallet para mopalit.',
            'roles' => [
                'seller' => [
                    'English' => 'Your Wallet tracks four numbers: Pending Balance (payment captured, awaiting delivery + your LGU\'s approval), Processing Amount (a withdrawal you\'ve requested that isn\'t paid out yet), Available Balance (ready to withdraw now), and Withdrawn Amount (already paid out). Ask me for your actual numbers any time.',
                ],
                'lgu_admin' => [
                    'English' => 'A seller\'s Wallet balance moves from Pending to Available once you approve their earnings for a completed order from the Seller Earnings tab -- you don\'t manage wallets directly, only the approval step that unlocks them.',
                ],
                'super_admin' => [
                    'English' => 'Seller wallets are Pending until their LGU approves earnings, then Available until the seller requests and you release a withdrawal in Payout Management. You can see any seller\'s wallet activity via their withdrawal history.',
                ],
            ],
        ],
        [
            'category' => 'Notifications',
            'keywords' => ['notification', 'why did i receive this', 'how do notifications work'],
            'English' => 'Notifications alert you about order updates, replies to reviews, and other account activity. Open the Notifications tab to see them, tap one to jump to the related page, and use "Mark All as Read" to clear your unread count in one tap.',
            'Tagalog' => 'Ang Notifications ay nag-aalerto sa iyo tungkol sa updates ng order, tugon sa reviews, at iba pang aktibidad ng account. Buksan ang Notifications tab para makita ang mga ito, pindutin ang isa para pumunta sa kaugnay na page, at gamitin ang "Mark All as Read" para burahin agad ang unread count.',
            'Bisaya' => 'Ang Notifications nag-alerto nimo bahin sa updates sa order, tubag sa reviews, ug uban pang kalihokan sa account. Ablihi ang Notifications tab para makita kini, i-tap ang usa para moadto sa may kalabutan nga page, ug gamita ang "Mark All as Read" para hawanan dayon ang wala mabasa nga count.',
            'roles' => [
                'seller' => [
                    'English' => 'You\'re notified about new orders, payments, review activity, and any listing action your LGU or the Super Admin takes. Open Notifications to see them, and use "Mark All as Read" to clear your unread count.',
                ],
                'lgu_admin' => [
                    'English' => 'You\'re notified when a seller has a completed order awaiting your earnings approval, plus other municipality activity. Open Notifications to see them, and use "Mark All as Read" to clear your unread count.',
                ],
                'super_admin' => [
                    'English' => 'You\'re notified about platform-wide activity such as new withdrawal requests awaiting payout. Open Notifications to see them, and use "Mark All as Read" to clear your unread count.',
                ],
            ],
        ],
        [
            'category' => 'Payments',
            'keywords' => ['pay with paymongo', 'how do i pay', 'checkout', 'payment'],
            'English' => 'Payments go through PayMongo checkout, opened from a listing\'s "Pay with PayMongo" button. Once payment is captured, it shows as Pending in the seller\'s wallet until your delivery is confirmed and the LGU approves the earnings release -- you don\'t need any separate wallet setup as a buyer.',
            'Tagalog' => 'Dumadaan ang bayad sa PayMongo checkout, binubuksan mula sa "Pay with PayMongo" button ng listing. Kapag nakuha na ang bayad, lalabas ito bilang Pending sa wallet ng seller hanggang makumpirma ang delivery mo at maaprubahan ng LGU ang earnings release -- wala kang kailangang hiwalay na wallet setup bilang buyer.',
            'Bisaya' => 'Ang bayad moagi sa PayMongo checkout, ablihan gikan sa "Pay with PayMongo" button sa listing. Kung nakuha na ang bayad, mogawas kini as Pending sa wallet sa seller hangtod makumpirma ang imong delivery ug ma-aprubahan sa LGU ang earnings release -- wala kay kinahanglan nga bulag nga wallet setup isip buyer.',
            'roles' => [
                'seller' => [
                    'English' => 'A buyer\'s payment is captured through PayMongo checkout on your listing. It lands as Pending Balance in your Wallet immediately, then becomes Available once the order is completed and your LGU approves the earnings release -- no action needed from you to receive it.',
                ],
                'lgu_admin' => [
                    'English' => 'Buyers pay sellers through PayMongo checkout; the captured payment sits as Pending until the order is completed, at which point it becomes eligible for you to approve in your Seller Earnings queue.',
                ],
                'super_admin' => [
                    'English' => 'All payments are processed through PayMongo checkout. You can review payment and payout activity platform-wide from Transactions and Payout Management.',
                ],
            ],
        ],
        [
            'category' => 'Payments',
            'keywords' => ['refund'],
            'English' => 'If a paid order is cancelled or expires, its payment shows Refund Pending until the refund is sent back to you, then Refunded. Only the seller can cancel a paid order, before it is out for delivery, so message them first about any problem. If the seller\'s account is suspended, every unfinished order (even one already out for delivery) is cancelled automatically and its payment refunded. If a refund is taking too long, send a support ticket from Help & Support (Contact Support) under "Payment or refund" with your order number.',
            'Tagalog' => 'Kapag na-cancel o nag-expire ang isang bayad na order, Refund Pending ang makikita sa bayad hanggang maibalik sa iyo ang refund, pagkatapos ay Refunded. Ang seller lang ang makaka-cancel ng bayad na order bago ito ma-deliver, kaya i-message muna sila kung may problema. Kapag na-suspend ang seller, awtomatikong kinakansela ang lahat ng hindi pa tapos na order (kahit nasa delivery na) at nire-refund ang bayad. Kung matagal ang refund, magpadala ng support ticket mula sa Help & Support (Contact Support) sa "Payment or refund" kasama ang order number mo.',
            'Bisaya' => 'Kung ma-cancel o ma-expire ang bayad na nga order, Refund Pending ang makita sa bayad hangtod mabalik nimo ang refund, unya Refunded. Ang seller ra ang maka-cancel sa bayad na nga order ayha kini ma-deliver, mao nga i-message una sila kung naay problema. Kung ma-suspend ang seller, awtomatikong ma-cancel ang tanang wala pa nahuman nga order (bisan naa na sa delivery) ug ma-refund ang bayad. Kung dugay ang refund, pagpadala og support ticket gikan sa Help & Support (Contact Support) sa "Payment or refund" uban ang imong order number.',
            'roles' => [
                'seller' => [
                    'English' => 'Cancelling a paid order (only possible before it is out for delivery) sends the buyer\'s payment to Refund Pending, and the AbaiMarket support team returns it to the buyer. You don\'t send refunds yourself. If your account is suspended, all your unfinished orders are cancelled automatically and the buyers refunded; completed orders are not affected. For a refund question you can\'t resolve, send a support ticket from Help & Support.',
                ],
                'lgu_admin' => [
                    'English' => 'Refunds for cancelled or expired paid orders are handled platform-wide by the Super Admin from the refund queue in Payout Management -- LGU admins don\'t process them. When a seller is suspended, their unfinished orders are cancelled and refunded automatically. Buyers with refund questions send a support ticket, which you can see and answer in your Support Tickets tab.',
                ],
                'super_admin' => [
                    'English' => 'Paid orders that are cancelled or expire land in the refund queue in Payout Management as Refund Pending -- including every unfinished order of a seller who gets suspended, which is cancelled automatically. Send the money back, then mark the payment Refunded. Buyers chasing a refund send a support ticket under "Payment or refund", which shows in your Support Tickets tab.',
                ],
            ],
        ],
        [
            'category' => 'Delivery',
            'keywords' => ['delivery', 'shipping', 'deliver', 'arrive', 'when will my order', 'order arrive', 'eta', 'pickup', 'pick up', 'received my order', 'order received'],
            'English' => 'Delivery is coordinated directly with the seller after your order is placed and paid -- check your order\'s pickup notes or message the seller to confirm timing and location. The seller updates the order status as it moves from confirmed to in-transit to completed.',
            'Tagalog' => 'Direktang inaayos ang delivery kasama ang seller pagkatapos mailagay at mabayaran ang order -- tingnan ang pickup notes ng order o i-message ang seller para kumpirmahin ang oras at lokasyon. Ina-update ng seller ang status ng order mula confirmed hanggang in-transit hanggang completed.',
            'Bisaya' => 'Direkta nga gi-coordinate ang delivery uban sa seller human mabutang ug mabayran ang order -- tan-awa ang pickup notes sa order o i-message ang seller para makumpirma ang oras ug lokasyon. Gi-update sa seller ang status sa order gikan sa confirmed hangtod in-transit hangtod completed.',
            'roles' => [
                'seller' => [
                    'English' => 'You coordinate delivery directly with the buyer -- add pickup notes and message them to confirm timing and location, then update the order status yourself as it moves from confirmed to in-transit to completed. A completed status is also what makes the payment eligible for LGU earnings approval.',
                ],
            ],
        ],
        [
            'category' => 'Reviews',
            'keywords' => ['review', 'rate', 'rating', 'leave a review', 'feedback', 'star rating', 'leave feedback'],
            'English' => 'Once an order is marked completed, a review option appears on that order in your Orders tab -- rate the seller 1-5 stars and add an optional comment. Your review contributes to the seller\'s public rating shown on their profile and listings.',
            'Tagalog' => 'Kapag na-mark na completed ang isang order, lalabas ang review option sa order na iyon sa Orders tab mo -- bigyan ng 1-5 star rating ang seller at maaaring magdagdag ng comment. Ang review mo ay nakakaapekto sa public rating ng seller na makikita sa profile at listings nila.',
            'Bisaya' => 'Kung ma-mark na completed ang usa ka order, mogawas ang review option sa maong order sa imong Orders tab -- hatagi og 1-5 star rating ang seller ug pwede magdugang og comment. Ang imong review makaapekto sa public rating sa seller nga makita sa ilang profile ug listings.',
            'roles' => [
                'seller' => [
                    'English' => 'Buyers can leave a 1-5 star review with a comment once you mark their order completed. You can\'t respond in-app yet, but every review updates your public rating shown on your profile and listings -- ask me "who left me a review" for the specifics.',
                ],
                'lgu_admin' => [
                    'English' => 'You can see reviews for every seller in your municipality from the Reviews tab -- useful context alongside verification and moderation decisions, though reviews themselves are left by buyers, not moderated by you.',
                ],
            ],
        ],
        [
            'category' => 'Messaging',
            'keywords' => [
                'contact', 'contact seller', 'message seller', 'chat seller', 'talk to seller',
                'chat with the seller', 'chat with seller', 'message the seller', 'contact the seller',
            ],
            'English' => 'Open any listing or seller profile and tap "Chat Seller" to start a conversation -- it opens directly in your Messages tab. You can keep messaging that seller any time after, and you\'ll see unread replies highlighted in your conversation list.',
            'Tagalog' => 'Buksan ang anumang listing o seller profile at pindutin ang "Chat Seller" para magsimula ng usapan -- direktang bubukas ito sa iyong Messages tab. Maaari kang magpatuloy sa pag-message sa seller na iyon anumang oras, at makikita mo ang mga hindi pa nababasang reply na naka-highlight sa listahan ng usapan.',
            'Bisaya' => 'Ablihi ang bisan unsa nga listing o seller profile ug i-tap ang "Chat Seller" para magsugod og estorya -- direkta ni moabli sa imong Messages tab. Pwede ka magpadayon og message sa maong seller bisan unsang orasa, ug makita nimo ang wala pa mabasa nga tubag naka-highlight sa listahan sa mga estorya.',
            'roles' => [
                'seller' => [
                    'English' => 'Buyers reach you through "Chat Seller" on your listing or profile, and every conversation lands in your Messages tab, alongside any LGU Admin or Super Admin who contacts you. Reply, edit, or delete your own messages within a short window after sending.',
                ],
                'lgu_admin' => [
                    'English' => 'You can message buyers and sellers within your own municipality, plus the Super Admin, directly from your Messages tab -- useful for resolving disputes or requesting clarification before approving or rejecting something.',
                ],
                'super_admin' => [
                    'English' => 'You can message any user on the platform -- buyers, sellers, or LGU admins -- directly from your Messages tab.',
                ],
            ],
        ],
        [
            'category' => 'Messaging',
            'keywords' => ['message', 'messaging', 'conversation'],
            'English' => 'The Messages tab lists every conversation with sellers you\'ve contacted. Open a thread to see the full history, send a new message, or edit/delete your own messages within a short window after sending. Unread messages are marked in your conversation list.',
            'Tagalog' => 'Ang Messages tab ay naglilista ng bawat usapan mo sa mga seller na na-contact mo. Buksan ang thread para makita ang buong history, magpadala ng bagong mensahe, o mag-edit/burahin ng sarili mong mensahe sa loob ng maikling panahon pagkatapos ipadala. May marka ang mga unread message sa listahan ng usapan.',
            'Bisaya' => 'Ang Messages tab naglista sa matag estorya nimo sa mga seller nga na-contact nimo. Ablihi ang thread para makita ang tibuok history, magpadala og bag-ong mensahe, o mag-edit/paghawa sa imong kaugalingong mensahe sulod sa mubo nga panahon human ipadala. Naay marka ang wala pa mabasa nga mensahe sa listahan sa mga estorya.',
            'roles' => [
                'seller' => [
                    'English' => 'The Messages tab lists every conversation with buyers (and LGU/Super Admin) who\'ve contacted you. Open a thread to reply, or edit/delete your own messages within a short window after sending.',
                ],
                'lgu_admin' => [
                    'English' => 'The Messages tab lists your conversations with buyers and sellers in your municipality, plus the Super Admin. Open a thread to reply, or edit/delete your own messages within a short window after sending.',
                ],
                'super_admin' => [
                    'English' => 'The Messages tab lists your conversations with any user on the platform. Open a thread to reply, or edit/delete your own messages within a short window after sending.',
                ],
            ],
        ],
        [
            'category' => 'Orders',
            'keywords' => ['order status', 'my order', 'orders', 'track', 'order history', 'past orders', 'track my order', 'where is my order'],
            'English' => 'Your Orders tab lists every purchase with its current status: placed, confirmed, out for delivery, or completed. Each order shows the seller, listing, and quantity, and a completed order unlocks the review option.',
            'Tagalog' => 'Ang Orders tab mo ay naglilista ng bawat binili mo kasama ang kasalukuyang status: placed, confirmed, out for delivery, o completed. Ipinapakita ng bawat order ang seller, listing, at dami, at ang completed order ay nagbubukas ng review option.',
            'Bisaya' => 'Ang imong Orders tab naglista sa matag palit nimo uban ang karon nga status: placed, confirmed, out for delivery, o completed. Gipakita sa matag order ang seller, listing, ug kantidad, ug ang completed nga order moabli sa review option.',
            'roles' => [
                'seller' => [
                    'English' => 'Your Orders tab lists every order placed against your listings. Update each one\'s status -- confirmed, in-transit, completed -- as you fulfill it; marking an order completed is also what makes its payment eligible for your LGU\'s earnings approval.',
                ],
                'lgu_admin' => [
                    'English' => 'Orders themselves are managed by buyers and sellers directly. As an LGU Admin your involvement starts once an order is completed and its payment is awaiting your earnings approval in the Seller Earnings tab.',
                ],
                'super_admin' => [
                    'English' => 'You can review every order platform-wide from Transactions. Orders are placed and fulfilled by buyers and sellers directly; your role is oversight, not day-to-day order management.',
                ],
            ],
        ],
        [
            'category' => 'Seller Information',
            'keywords' => ['seller rating', 'verified seller', 'trust'],
            'English' => 'Every seller has a star rating (1-5) based on buyer reviews, shown on their profile and listing cards. A "Verified Seller" badge means the LGU has reviewed and approved their hatchery credentials -- look for both when choosing who to buy from.',
            'Tagalog' => 'Bawat seller ay may star rating (1-5) batay sa reviews ng buyer, ipinapakita sa profile at listing cards nila. Ang "Verified Seller" badge ay nangangahulugang na-review at na-approve na ng LGU ang kanilang hatchery credentials -- tingnan ang pareho kapag pumipili kung kanino bibili.',
            'Bisaya' => 'Ang matag seller naay star rating (1-5) base sa reviews sa buyer, gipakita sa ilang profile ug listing cards. Ang "Verified Seller" badge nagpasabot nga na-review ug na-aprubahan na sa LGU ang ilang hatchery credentials -- tan-awa ang duha kung mopili kinsay palitan.',
            'roles' => [
                'lgu_admin' => [
                    'English' => 'You verify sellers in your municipality from the Sellers tab -- review their hatchery details and mark them Verified, or Suspend a seller who violates marketplace rules (their listings leave the marketplace and they cannot sell, but they can still sign in to read why and contact you).',
                ],
            ],
        ],
        [
            'category' => 'Fish Care',
            'keywords' => ['beginner', 'good species', 'what species', 'which species', 'species available'],
            'English' => 'For beginners, Tilapia is a great starting point -- hardy, tolerates imperfect water, grows fast (about 6 months to harvest), and has strong market demand. AbaiMarket also lists Bangus, Tuna, Catfish, Sea Bass, and Carp fingerlings; use the Species filter on Browse to compare what local sellers currently have in stock.',
            'Tagalog' => 'Para sa mga baguhan, magandang simulan ang Tilapia -- matibay, kayang mag-adjust sa di-perpektong tubig, mabilis lumaki (mga 6 buwan hanggang harvest), at may mataas na demand sa merkado. May Bangus, Tuna, Catfish, Sea Bass, at Carp fingerlings din sa AbaiMarket -- gamitin ang Species filter sa Browse para makita kung ano ang available ngayon.',
            'Bisaya' => 'Para sa mga baguhan, maayo ang Tilapia isugod -- lig-on siya, ka-adjust sa dili perpekto nga tubig, paspas motubo (mga 6 ka bulan hangtod sa harvest), ug taas ang demand sa merkado. Naa say Bangus, Tuna, Catfish, Sea Bass, ug Carp fingerlings sa AbaiMarket -- gamita ang Species filter sa Browse para makita unsay naa karon.',
        ],
        [
            'category' => 'Fish Care',
            'keywords' => ['fingerling', 'fry', 'stock density', 'stocking density'],
            'English' => 'Fingerlings are young fish, usually 1-2 inches. They need a high-protein diet (35-40%), water temperature around 25-30°C, and low stocking density at first to reduce stress and disease risk. Increase density gradually as they grow, and always acclimate new stock to your pond\'s temperature before releasing them.',
            'Tagalog' => 'Ang fingerlings ay mga batang isda, karaniwang 1-2 pulgada. Kailangan nila ng mataas na protina (35-40%), temperatura ng tubig na 25-30°C, at mababang stocking density sa simula para mabawasan ang stress at sakit. Unti-unting dagdagan ang density habang lumalaki, at laging i-acclimate ang bagong stock sa temperatura ng iyong pond bago palayain.',
            'Bisaya' => 'Ang fingerlings mga batan-on nga isda, kasagaran 1-2 pulgada. Kinahanglan nila og taas nga protina (35-40%), temperatura sa tubig nga 25-30°C, ug ubos nga stocking density sa sinugdan aron maminusan ang stress ug sakit. Dugangi hinay-hinay ang density samtang motubo sila, ug siguroha nga na-acclimate ang bag-ong stock sa temperatura sa imong pond ayha buhian.',
        ],
        [
            'category' => 'Fish Care',
            'keywords' => ['water', 'quality', 'pond preparation', 'prepare my pond'],
            'English' => 'Key water quality targets: dissolved oxygen 6-8 mg/L, pH 6.5-7.5, ammonia below 0.05 mg/L, and temperature 25-30°C. Change 20-30% of pond water weekly. Before stocking, prepare your pond by draining and drying it, removing predators, applying lime to balance pH, and letting it fill and stabilize for a few days.',
            'Tagalog' => 'Mga target sa kalidad ng tubig: dissolved oxygen 6-8 mg/L, pH 6.5-7.5, ammonia mas mababa sa 0.05 mg/L, at temperatura 25-30°C. Palitan ang 20-30% ng tubig ng pond linggo-linggo. Bago mag-stock, ihanda ang pond sa pamamagitan ng pagpapatuyo, pag-alis ng mga predator, paglalagay ng apog para balansehin ang pH, at hayaang tumatag ng ilang araw.',
            'Bisaya' => 'Mga target sa kalidad sa tubig: dissolved oxygen 6-8 mg/L, pH 6.5-7.5, ammonia ubos sa 0.05 mg/L, ug temperatura 25-30°C. Ilisi ang 20-30% sa tubig sa pond kada semana. Ayha mag-stock, andama ang pond pinaagi sa pagpahubas, pagkuha sa mga predator, pagbutang og apog para sa pH, ug pasagdi nga mahamtang og pipila ka adlaw.',
        ],
        [
            'category' => 'Fish Care',
            'keywords' => ['feed', 'feeding'],
            'English' => 'Feed fingerlings 2-4 times daily at 3-5% of their body weight, adjusting as they grow. Use a high-protein starter feed early on, and watch for uneaten feed -- it fouls water quality quickly. Reduce feeding if fish appear sluggish or water quality drops.',
            'Tagalog' => 'Pakainin ang fingerlings 2-4 beses araw-araw sa 3-5% ng kanilang timbang, isasaayos habang lumalaki. Gumamit ng high-protein starter feed sa simula, at bantayan ang natirang pagkain -- mabilis nitong sinisira ang kalidad ng tubig. Bawasan ang pagpapakain kung mukhang tamad ang isda o bumaba ang kalidad ng tubig.',
            'Bisaya' => 'Pakan-a ang fingerlings 2-4 ka beses matag adlaw sa 3-5% sa ilang gibug-aton, i-adjust samtang motubo. Gamita ang high-protein starter feed sa sinugdan, ug bantayi ang wala nakaon nga pagkaon -- paspas ni makadaot sa kalidad sa tubig. Kunhoron ang pagpakaon kung ang isda daw tapulan o mikunhod ang kalidad sa tubig.',
        ],
        [
            'category' => 'Fish Care',
            'keywords' => ['harvest'],
            'English' => 'Most species reach harvest size in 5-8 months depending on stocking density and feeding. Stop feeding 24 hours before harvest, harvest early in the morning when water is cooler, and handle fish gently to reduce stress and preserve quality for sale.',
            'Tagalog' => 'Karamihan sa species ay umaabot sa harvest size sa loob ng 5-8 buwan depende sa stocking density at feeding. Itigil ang pagpapakain 24 oras bago mag-harvest, mag-harvest nang maaga sa umaga kung mas malamig ang tubig, at hawakan nang maayos ang isda para mabawasan ang stress at mapanatili ang kalidad.',
            'Bisaya' => 'Kadaghanan sa species moabot sa harvest size sulod sa 5-8 ka bulan depende sa stocking density ug feeding. Ihunong ang pagpakaon 24 oras ayha mag-harvest, mag-harvest og sayo sa buntag kung bugnaw pa ang tubig, ug ayoha paghikap ang isda aron maminusan ang stress ug mapreserbar ang kalidad.',
        ],
        // -- New role-aware topics (appended, never inserted earlier) so every
        // existing keyword match above keeps resolving to the exact same
        // category it always has.
        // Notices to Explain, suspension and reinstatement (rules of
        // 2026-10-09). Placed BEFORE the generic Account topic so "my account
        // is suspended" lands here, not on profile settings. Reuses the
        // 'Account' category so CATEGORIES is unchanged. Phrase keywords only,
        // so "suspended sellers" style admin data questions (answered earlier
        // by AiDataQueryResolver) and the Reports topic are not affected.
        [
            'category' => 'Account',
            'keywords' => [
                'notice to explain', 'nte', 'explanation rejected', 'explanation was rejected', 'reject my explanation',
                'account suspended', 'account is suspended', 'am i suspended', 'got suspended', 'been suspended', 'why suspended',
                'reinstate', 'reinstated', 'unsuspend', 'lift my suspension',
            ],
            'English' => 'A seller gets a Notice to Explain when a review of 3 stars or fewer brings their average rating below 3, or when their LGU finds a buyer\'s report against them valid. A 4 or 5 star review never causes one. The notice lists the low reviews behind it. Every notice works the same: the seller is not suspended and their listings stay up while they send ONE explanation from the Notices tab. If the LGU accepts it, nothing happens to the account. If the LGU rejects it, the seller account is suspended: listings leave the marketplace, but the seller can still sign in. There is no dispute button for this, and a suspended seller cannot explain in the app or get new notices -- to be reviewed again, message your LGU or send a support ticket from Help & Support, and your LGU or the Super Admin can reinstate the account. After a rejected explanation, the seller\'s NEXT notice (from a rating or a report) suspends them right away with nothing to explain: they message their LGU or send a support ticket. An accepted explanation does not count against them.',
            'Tagalog' => 'Nakakatanggap ang seller ng Notice to Explain kapag ang isang review na 3 stars pababa ay nagpababa sa average rating niya sa ilalim ng 3, o kapag nakita ng LGU na totoo ang report ng isang buyer laban sa kanya. Hindi kailanman nagdudulot nito ang 4 o 5 star na review. Nakalista sa notice ang mga mababang review. Pareho ang bawat notice: hindi masususpinde ang seller at mananatili ang mga listing habang nagpapadala siya ng isang paliwanag mula sa Notices tab. Kapag tinanggap ng LGU, walang mangyayari sa account. Kapag tinanggihan, masususpinde ang seller account: aalisin ang mga listing sa marketplace, pero makakapag-sign in pa rin. Walang dispute button para dito, at ang suspended na seller ay hindi na makakapagpaliwanag sa app o makakatanggap ng bagong notice -- para masuri ulit, i-message ang iyong LGU o magpadala ng support ticket mula sa Help & Support, at maaaring i-reinstate ng LGU o ng Super Admin ang account. Kapag may natanggihang paliwanag na dati, ang SUSUNOD na notice (mula sa rating o report) ay agad na magsususpinde sa seller at wala nang maipapaliwanag: i-message ang LGU o magpadala ng support ticket. Hindi ito binibilang kung tinanggap ang paliwanag.',
            'Bisaya' => 'Makadawat ang seller og Notice to Explain kung ang usa ka review nga 3 stars paubos makapaubos sa iyang average rating ubos sa 3, o kung makita sa LGU nga tinuod ang report sa usa ka buyer batok niya. Ang 4 o 5 star nga review dili gyud hinungdan niini. Nakalista sa notice ang mga ubos nga review. Parehas ang matag notice: dili ma-suspend ang seller ug magpabilin ang mga listing samtang magpadala siya og usa ka pasabot gikan sa Notices tab. Kung dawaton sa LGU, walay mahitabo sa account. Kung isalikway, ma-suspend ang seller account: tangtangon ang mga listing sa marketplace, pero makasign in gihapon. Walay dispute button para niini, ug ang suspended nga seller dili na makapasabot sa app o makadawat og bag-ong notice -- aron masusi pag-usab, i-message ang imong LGU o magpadala og support ticket gikan sa Help & Support, ug ang LGU o ang Super Admin makahimo sa pag-reinstate sa account. Kung naay na-salikway nga pasabot kaniadto, ang SUNOD nga notice (gikan sa rating o report) mo-suspend dayon sa seller ug wala nay ikapasabot: i-message ang LGU o magpadala og support ticket. Dili kini maihap kung gidawat ang pasabot.',
            'roles' => [
                'lgu_admin' => [
                    'English' => 'Notices to Explain come from a review of 3 stars or fewer that brings the seller\'s average below 3, or from a buyer report you resolve as "Valid: send Notice to Explain". Each notice lists the low reviews behind it. Every notice works the same: the seller keeps selling while they send one explanation, and you get a notification. Accepting closes it with no offense. Rejecting records an offense and suspends the seller automatically. A suspended seller -- whether from a rejected explanation or suspended by you directly -- cannot explain in the app and gets no new notices. A seller who already had an explanation rejected is suspended automatically on their next notice, marked Repeat Offense, with nothing to explain. There is no in-app dispute: the seller messages you or sends a support ticket, and you can reinstate them from the Sellers tab with a reason.',
                ],
                'super_admin' => [
                    'English' => 'Notices to Explain come from a review of 3 stars or fewer that brings the seller\'s average below 3, or from a buyer report resolved as "Valid: send Notice to Explain". Each notice lists the low reviews behind it, and every notice works the same way. You and the seller\'s LGU are both notified when the seller explains, and either of you can decide it. Rejecting records an offense and suspends the seller automatically. A suspended seller -- however it happened -- cannot explain in the app and gets no new notices. A seller who already had an explanation rejected is suspended automatically on their next notice (Repeat Offense), with nothing to explain. There is no in-app dispute: the seller messages their LGU or sends a support ticket, and the account can be reinstated from the Sellers tab with a reason.',
                ],
            ],
        ],
        [
            'category' => 'Account',
            'keywords' => ['my account', 'my profile', 'update my profile', 'change my password', 'change password', 'profile picture', 'edit my profile', 'account settings'],
            'English' => 'Update your name, phone, and profile picture from your Profile tab, or change your password from Account Settings.',
            'Tagalog' => 'I-update ang iyong pangalan, numero, at profile picture mula sa Profile tab, o palitan ang password mula sa Account Settings.',
            'Bisaya' => 'I-update ang imong ngalan, numero, ug profile picture gikan sa Profile tab, o ilisi ang password gikan sa Account Settings.',
            'roles' => [
                'seller' => [
                    'English' => 'Update your hatchery profile (bio, farming practices, address), profile picture, and cover photo from your Profile tab, or change your password from Account Settings.',
                ],
                'lgu_admin' => [
                    'English' => 'You can change your password from Account Settings. Your name, email, and assigned municipality are managed by the Super Admin.',
                ],
                'super_admin' => [
                    'English' => 'You can change your password from Account Settings. Your account isn\'t tied to any single municipality.',
                ],
            ],
        ],
        [
            'category' => 'Listings',
            'keywords' => [
                'create a listing', 'add a listing', 'edit my listing', 'update my listing', 'delete my listing',
                'manage my listings', 'post a listing', 'how do i list my', 'approve a listing', 'reject a listing',
                'listing approval', 'archive a listing',
                'sell fingerlings', 'how do i sell', 'how to sell', 'start selling', 'list my fingerlings', 'out of stock',
            ],
            'English' => 'Listings are created by Sellers and must be approved by their municipality\'s LGU Admin before they appear in the Marketplace. As a Buyer you can browse and filter approved listings from Marketplace or Browse.',
            'Tagalog' => 'Ang mga listing ay ginagawa ng Seller at kailangang aprubahan ng LGU Admin ng kanilang munisipyo bago lumabas sa Marketplace. Bilang Buyer, maaari kang mag-browse at mag-filter ng mga approved na listing mula sa Marketplace o Browse.',
            'Bisaya' => 'Ang mga listing gihimo sa Seller ug kinahanglan aprubahan sa LGU Admin sa ilang munisipyo ayha mogawas sa Marketplace. Isip Buyer, pwede ka mag-browse ug mag-filter sa mga approved nga listing gikan sa Marketplace o Browse.',
            'roles' => [
                'seller' => [
                    'English' => 'Create a listing from your Listings tab with species, quantity, price, and photos -- it starts Pending until your LGU Admin approves it. You can edit details, reorder or remove photos, and update or delete the listing any time; a listing with existing orders can only be archived, not deleted.',
                ],
                'lgu_admin' => [
                    'English' => 'Review pending listings from your Approvals tab -- Approve to publish it to the Marketplace, or Reject with a reason to send it back to the seller. Listing Management also lets you Archive or Delete an already-published listing in your municipality if it violates marketplace rules.',
                ],
                'super_admin' => [
                    'English' => 'Listing Management gives you platform-wide control -- edit, approve, reject, archive, or delete any listing in any municipality, on top of each municipality\'s own LGU review.',
                ],
            ],
        ],
        [
            'category' => 'Withdrawals',
            'keywords' => ['withdraw', 'withdrawal', 'payout', 'payouts', 'cash out', 'how do i get paid', 'withdrawal rejected', 'payout rejected'],
            // States outright that withdrawing is free, because sellers ask
            // what it costs. The shares are interpolated from the constants
            // rather than typed, so a rate change can never leave this stale.
            'English' => 'Withdrawals let a Seller cash out their Available Balance via GCash, Maya, or bank transfer. There is no payout fee -- the Platform\'s '.CommissionCalculator::PLATFORM_PERCENT.'% and the LGU\'s '.CommissionCalculator::LGU_PERCENT.'% are taken when each order is settled, so a seller receives the full amount requested. The Seller requests a withdrawal, and the Super Admin reviews, approves, and marks it paid before the amount moves from Available Balance to Withdrawn Amount. If a withdrawal is rejected, the amount stays on hold for '.WithdrawalRejection::DISPUTE_DAYS.' days so it can be disputed once; there is no Accept Rejection button. If no dispute is filed, or the dispute is rejected, the amount returns to the Available Balance and can be requested again.',
            'Tagalog' => 'Sa Withdrawals, maaaring i-cash out ng Seller ang kanilang Available Balance via GCash, Maya, o bank transfer. Walang payout fee -- ang '.CommissionCalculator::PLATFORM_PERCENT.'% ng Platform at '.CommissionCalculator::LGU_PERCENT.'% ng LGU ay kinukuha na kapag na-settle ang order, kaya buo ang natatanggap ng seller. Nag-rerequest ang Seller ng withdrawal, at ang Super Admin ang sumusuri, umaaprub, at nagmamarka nito bilang bayad bago ito lumipat mula Available Balance patungong Withdrawn Amount. Kapag na-reject ang withdrawal, naka-hold ang halaga nang '.WithdrawalRejection::DISPUTE_DAYS.' araw para ma-dispute ito nang isang beses; walang Accept Rejection na button. Kung walang dispute, o na-reject ang dispute, babalik ang halaga sa Available Balance at puwede itong i-request ulit.',
            'Bisaya' => 'Ang Withdrawals nagtugot sa Seller nga i-cash out ang ilang Available Balance pinaagi sa GCash, Maya, o bank transfer. Walay payout fee -- ang '.CommissionCalculator::PLATFORM_PERCENT.'% sa Platform ug '.CommissionCalculator::LGU_PERCENT.'% sa LGU gikuha na inig settle sa order, mao nga tibuok ang madawat sa seller. Mo-request ang Seller og withdrawal, ug ang Super Admin ang mo-review, mo-aprubar, ug mag-marka niini nga bayad ayha kini mobalhin gikan sa Available Balance ngadto sa Withdrawn Amount. Kung ma-reject ang withdrawal, i-hold ang kantidad sulod sa '.WithdrawalRejection::DISPUTE_DAYS.' ka adlaw aron ma-dispute kini kausa; walay Accept Rejection nga button. Kung walay dispute, o ma-reject ang dispute, mobalik ang kantidad sa Available Balance ug pwede kini i-request pag-usab.',
            'roles' => [
                'seller' => [
                    'English' => 'To withdraw: open your Wallet and request a withdrawal for up to your Available Balance via GCash, Maya, or bank transfer. There is no payout fee, so you receive the full amount you request. The Super Admin reviews and approves it, then marks it paid -- at that point it moves from Available Balance to Withdrawn Amount. If it is rejected, the amount is on hold for '.WithdrawalRejection::DISPUTE_DAYS.' days: click Dispute This Rejection on the request to explain your side once. If you do not, or your dispute is rejected, the amount goes back to your Available Balance. After that, message the Super Admin or send a support ticket under Wallet or withdrawal.',
                ],
                'lgu_admin' => [
                    'English' => 'Seller withdrawals are handled platform-wide by the Super Admin, after a seller\'s earnings have been released through your Seller Earnings approval. Your municipality\'s own share is withdrawn from the LGU Wallet, and the Super Admin reviews that request too. If it is rejected, the amount is on hold for '.WithdrawalRejection::DISPUTE_DAYS.' days so you can dispute it once; if you do not, or the dispute is rejected, it returns to your Available Balance. You also decide your own sellers\' disputes from the Disputes tab.',
                ],
                'super_admin' => [
                    'English' => 'Withdrawal requests appear in Payout Management. Review each one, Approve it once verified, then Mark Paid after you\'ve sent the funds -- or Reject with a reason if it can\'t be honored. Approving or marking paid notifies the seller automatically. A rejected amount stays on hold for '.WithdrawalRejection::DISPUTE_DAYS.' days so the seller (or LGU) can dispute it once; their disputes appear on the Disputes tab and in the Pending Seller Disputes card on your dashboard.',
                ],
            ],
        ],
        [
            'category' => 'Seller Earnings',
            'keywords' => ['seller earnings', 'earnings approval', 'approve earnings', 'release my earnings', 'pending balance approval', 'when do i get paid'],
            'English' => 'A seller\'s payment is captured as soon as a buyer pays, and sits as Pending Balance until the order is marked completed and the seller\'s LGU approves the earnings release -- only then does it become Available Balance.',
            'Tagalog' => 'Nakukuha na ang bayad ng seller sa oras na magbayad ang buyer, at nananatili itong Pending Balance hanggang ma-mark completed ang order at aprubahan ng LGU ng seller ang earnings release -- saka pa lang ito magiging Available Balance.',
            'Bisaya' => 'Ang bayad sa seller nakuha dayon inig bayad sa buyer, ug magpabilin kini nga Pending Balance hangtod ma-mark completed ang order ug aprubahan sa LGU sa seller ang earnings release -- ana pa lang kini mahimong Available Balance.',
            'roles' => [
                'lgu_admin' => [
                    'English' => 'Completed orders with a captured payment appear in your Seller Earnings queue, scoped to your municipality. Approving one releases that payment from the seller\'s Pending Balance into their Available Balance -- only completed (delivered) orders are eligible.',
                ],
                'super_admin' => [
                    'English' => 'Seller earnings are approved by each seller\'s municipality LGU Admin once an order is completed. You don\'t approve earnings directly, but you do review and release the resulting withdrawal requests in Payout Management.',
                ],
            ],
        ],
        [
            'category' => 'Reports',
            'keywords' => ['reports page', 'view reports', 'municipality report', 'platform report', 'reports tab'],
            'English' => 'Reports are available to LGU Admins (municipality-scoped) and the Super Admin (platform-wide) -- they summarize registered sellers/buyers, listings by status and species, and activity over a selectable period.',
            'Tagalog' => 'Available ang Reports sa LGU Admins (nasa loob ng munisipyo) at sa Super Admin (buong platform) -- ibinubuod nito ang rehistradong seller/buyer, listing ayon sa status at species, at aktibidad sa loob ng napiling panahon.',
            'Bisaya' => 'Ang Reports naa alang sa LGU Admins (sulod sa munisipyo) ug sa Super Admin (tibuok platform) -- gisumaryo ang rehistradong seller/buyer, listing base sa status ug species, ug kalihokan sulod sa gipili nga panahon.',
            'roles' => [
                'lgu_admin' => [
                    'English' => 'Your Reports tab summarizes your municipality: registered sellers, buyers, listings by status and species, and order activity over a selectable period (daily/weekly/monthly/yearly).',
                ],
                'super_admin' => [
                    'English' => 'Your Reports tab summarizes the whole platform: totals for LGUs, sellers, buyers, listings, and pending payouts, plus charts by municipality and species over a selectable period.',
                ],
                'buyer' => [
                    'English' => 'Reports are a moderation/oversight view for LGU Admins and the Super Admin. As a Buyer, your own activity is in Analytics instead.',
                ],
                'seller' => [
                    'English' => 'Reports are a moderation/oversight view for LGU Admins and the Super Admin. As a Seller, your own sales activity is in Analytics instead.',
                ],
            ],
        ],
        [
            'category' => 'Analytics',
            'keywords' => ['analytics', 'sales trend', 'performance dashboard', 'my analytics', 'view analytics', 'spending trend'],
            'English' => 'Your Analytics tab shows your purchase history over a selectable period -- total orders, total spending, your favorite species, and orders by status.',
            'Tagalog' => 'Ipinapakita ng iyong Analytics tab ang kasaysayan ng iyong pagbili sa loob ng napiling panahon -- kabuuang order, kabuuang gastos, paboritong species, at order ayon sa status.',
            'Bisaya' => 'Gipakita sa imong Analytics tab ang kasaysayan sa imong pagpalit sulod sa gipili nga panahon -- total nga order, total nga gasto, paborito nga species, ug order base sa status.',
            'roles' => [
                'seller' => [
                    'English' => 'Your Analytics tab shows sales over a selectable period -- total revenue, completed sales, orders by status, and your top-selling species.',
                ],
                'lgu_admin' => [
                    'English' => 'Municipality-level analytics live on your Reports tab -- listings by status/species, seller registrations, and order volume over a selectable period, scoped to your municipality.',
                ],
                'super_admin' => [
                    'English' => 'Platform-wide analytics live on your Reports tab -- order volume, listings and sellers by municipality and species, over a selectable period.',
                ],
            ],
        ],
        [
            'category' => 'Municipality',
            'keywords' => ['municipalities list', 'which municipalities', 'what municipality', 'municipality information', 'about the municipality', 'municipalities are covered'],
            'English' => 'AbaiMarket operates across several municipalities, each supervised by its own LGU Admin who approves the sellers and listings registered there. Ask me for counts (e.g. "how many sellers are in Cordova?") for live numbers.',
            'Tagalog' => 'Ang AbaiMarket ay gumagana sa ilang munisipyo, bawat isa ay sinusubaybayan ng sariling LGU Admin na siyang nag-aaprubang ng mga seller at listing na naka-rehistro doon. Magtanong ng bilang (hal. "ilan ang seller sa Cordova?") para sa live na numero.',
            'Bisaya' => 'Ang AbaiMarket naglihok sa daghang munisipyo, matag usa gibantayan sa kaugalingong LGU Admin nga nag-aprubar sa mga seller ug listing nga narehistro didto. Pangutana og count (pananglitan, "pila ka seller sa Cordova?") para sa live nga numero.',
        ],
        // -- Second appended block (Cart + general Fish Care). Kept at the very
        // end so, together with the two-pass matcher, no existing single-word
        // keyword above changes the category it always resolved to.
        [
            // Reuses the existing 'Marketplace' category (rather than adding a
            // new one) so CATEGORIES and any category-based telemetry are
            // untouched; the Cart is a buyer marketplace feature.
            'category' => 'Marketplace',
            'keywords' => ['cart', 'add to cart', 'save for later', 'shopping cart', 'my cart', 'saved listing', 'saved listings'],
            'English' => 'The Cart lets you save listings to buy later -- open a listing and tap "Add to Cart". Saving doesn\'t reserve stock or lock the price; both are re-checked when you actually buy, and each cart item is checked out as its own order from the Cart tab.',
            'Tagalog' => 'Sa Cart, maaari mong i-save ang mga listing para bilhin mamaya -- buksan ang isang listing at pindutin ang "Add to Cart". Ang pag-save ay hindi nagre-reserve ng stock o nagla-lock ng presyo; sinusuri ulit ang dalawa kapag talagang bumili ka, at bawat item sa cart ay chi-check out bilang sariling order mula sa Cart tab.',
            'Bisaya' => 'Ang Cart magtugot nimo nga i-save ang mga listing para paliton unya -- ablihi ang usa ka listing ug i-tap ang "Add to Cart". Ang pag-save wala magreserba og stock o mag-lock sa presyo; susihon usab ang duha inig palit gyud nimo, ug ang matag item sa cart i-checkout as kaugalingong order gikan sa Cart tab.',
            'roles' => [
                'seller' => [
                    'English' => 'The Cart is a Buyer feature -- buyers save your listings there to purchase later. As a Seller you don\'t have a cart; you manage your listings and fulfill the orders buyers place.',
                ],
                'lgu_admin' => [
                    'English' => 'The Cart is a Buyer feature for saving listings to buy later. It isn\'t part of LGU administration -- your tools are listing approval, seller verification, and earnings approval.',
                ],
                'super_admin' => [
                    'English' => 'The Cart is a Buyer feature for saving listings to buy later. It isn\'t part of platform administration.',
                ],
            ],
        ],
        // Placed before the general Fish Care catch-all below so "how do I know
        // if my fish are sick?" gets these concrete warning signs rather than
        // the catch-all's "ask me something specific" prompt. That matters most
        // exactly when Gemini is unreachable, since this text is then the whole
        // answer -- a farmer inspecting a pond needs something to look FOR.
        [
            'category' => 'Fish Care',
            // Every keyword here carries the SUBJECT. Question-form openers
            // ("how to know if", "how do i know if", "signs of") were tried and
            // removed: they match any question shaped that way, so "how do I
            // know if my withdrawal was approved" resolved to Fish Care. The
            // phrase pass runs across all topics before any single word, so a
            // topic-agnostic phrase here outranks every real topic below it.
            'keywords' => [
                'sick', 'symptom', 'healthy', 'unhealthy', 'is my fish', 'are my fish',
                'may sakit',
            ],
            'English' => 'Healthy fish swim actively, feed eagerly, and have clear eyes with smooth, unbroken fins. Warning signs: gasping at the surface, swimming alone or in circles, refusing feed, white spots or cottony patches, red or frayed fins, open sores, bloating, bulging eyes, or pale/darkened colour. Check your water first -- low oxygen and high ammonia cause most of these. Remove dead fish immediately so they do not foul the pond, and if several die within a day or two, contact BFAR or your municipal fisheries technician.',
            'Tagalog' => 'Ang malusog na isda ay aktibong lumalangoy, gutom sa pagkain, may malinaw na mata at makinis na palikpik. Mga babala: humihingal sa ibabaw, nag-iisang lumalangoy o paikot-ikot, ayaw kumain, may puting batik o parang bulak, mapula o sirang palikpik, may sugat, namamaga, umuumbok ang mata, o namumutla/nangingitim. Suriin muna ang tubig -- kulang na oxygen at sobrang ammonia ang karaniwang dahilan. Agad alisin ang patay na isda para hindi mabaho ang pond, at kung maraming namamatay sa loob ng isa o dalawang araw, tumawag sa BFAR o sa fisheries technician ng inyong munisipyo.',
            'Bisaya' => 'Ang himsog nga isda aktibo molangoy, gana mokaon, tin-aw ang mata ug hamis ang palikpik. Mga timailhan sa sakit: naghangos sa ibabaw, nag-inusara molangoy o naglibot-libot, dili mokaon, naay puti nga tulbok o samag gapas, pula o gisi nga palikpik, naay samad, nagburot, nagbudlot ang mata, o naluspad/miitom. Susiha una ang tubig -- ang kulang nga oxygen ug taas nga ammonia maoy kasagarang hinungdan. Kuhaa dayon ang patay nga isda aron dili mabaho ang pond, ug kung daghan ang mamatay sulod sa usa o duha ka adlaw, kontaka ang BFAR o ang fisheries technician sa inyong munisipyo.',
        ],
        [
            'category' => 'Fish Care',
            'keywords' => [
                'fish farming', 'fish care', 'aquaculture', 'raising fish', 'raise fish', 'grow fish', 'farm fish',
                'disease', 'sick fish', 'fish disease', 'dying', 'aeration', 'oxygen', 'algae',
            ],
            'English' => 'AbaiMarket\'s AI can help with fish-farming basics -- choosing a species, preparing and stocking a pond, water quality, feeding, disease prevention, and harvesting. Ask a specific question (e.g. "how do I keep pond oxygen up?" or "why are my fingerlings dying?") and I\'ll give practical guidance for local conditions.',
            'Tagalog' => 'Makakatulong ang AI ng AbaiMarket sa mga batayan ng fish farming -- pagpili ng species, paghahanda at pag-stock ng pond, kalidad ng tubig, pagpapakain, pag-iwas sa sakit, at pag-harvest. Magtanong nang tiyak (hal. "paano ko itaas ang oxygen sa pond?" o "bakit namamatay ang aking fingerlings?") at bibigyan kita ng praktikal na gabay para sa lokal na kondisyon.',
            'Bisaya' => 'Ang AI sa AbaiMarket makatabang sa mga sukaranan sa fish farming -- pagpili og species, pag-andam ug pag-stock sa pond, kalidad sa tubig, pagpakaon, pag-iwas sa sakit, ug pag-harvest. Pangutana og espisipiko (pananglitan, "unsaon nako pagpataas sa oxygen sa pond?" o "nganong nangamatay ang akong fingerlings?") ug hatagan tika og praktikal nga giya para sa lokal nga kondisyon.',
        ],
        // Help & Support. Reuses the 'Account' category, like the Cart reuses
        // 'Marketplace', so CATEGORIES stays unchanged. Phrase keywords only: a bare
        // "support" or "help" would catch "does AbaiMarket support GCash?" or a
        // farming question. Support is a ticket sent from the contact form, answered by email.
        [
            'category' => 'Account',
            'keywords' => [
                'contact support', 'customer support', 'customer service', 'support email', 'support team',
                'abaimarket support', 'support ticket', 'my ticket', 'closed ticket', 'ticket is closed', 'help and support', 'help & support', 'help center', 'help centre',
                'talk to a person', 'talk to a human', 'talk to someone', 'speak to someone', 'real person',
                'report a bug', 'found a bug', 'report a problem', 'contact the admin', 'contact admin',
                'give feedback', 'send feedback',
                'paano humingi ng tulong', 'kanino ako lalapit', 'asa ko mangayo og tabang',
            ],
            'English' => 'Open Help & Support in your sidebar for answers to common questions -- anyone can also read the Help Center at /help without logging in. If you still need help, or want to give feedback, click Contact Support and send a support ticket. It is not real-time support: your LGU or the AbaiMarket support team reviews it and replies on your ticket under My Tickets, and you get an email when your ticket is received, replied to or closed. Support may message you there to clarify your issue, so check it from time to time; once they have, you can reply there. Once a ticket is closed it can no longer be replied to, so send a new ticket if you still need help. Include your order number if it is about an order.',
            'Tagalog' => 'Buksan ang Help & Support sa sidebar para sa sagot sa mga karaniwang tanong -- mababasa rin ng kahit sino ang Help Center sa /help kahit hindi naka-login. Kung kailangan mo pa ng tulong, o may feedback ka, i-click ang Contact Support at magpadala ng support ticket. Hindi ito real-time support: rerepasuhin ito ng iyong LGU o ng AbaiMarket support team at sasagot sila sa iyong ticket sa My Tickets, kung saan mo rin ito masasagot. Makakatanggap ka ng email kapag natanggap, nasagot o naisara ang ticket mo. Kapag sarado na ang ticket, hindi na ito masasagot, kaya magpadala ng bagong ticket kung kailangan mo pa ng tulong. Isama ang order number kung tungkol ito sa isang order.',
            'Bisaya' => 'Ablihi ang Help & Support sa sidebar para sa tubag sa kasagarang pangutana -- mabasa usab sa bisan kinsa ang Help Center sa /help bisan dili naka-login. Kung kinahanglan pa nimo og tabang, o naa kay feedback, i-click ang Contact Support ug pagpadala og support ticket. Dili kini real-time support: susihon kini sa imong LGU o sa AbaiMarket support team ug motubag sila sa imong ticket sa My Tickets, diin nimo usab kini matubag. Makadawat ka og email kung madawat, matubag o masirado ang imong ticket. Kung sirado na ang ticket, dili na kini matubag, mao nga pagpadala og bag-ong ticket kung kinahanglan pa nimo og tabang. Iapil ang order number kung bahin kini sa usa ka order.',
            'roles' => [
                'seller' => [
                    'English' => 'Open Help & Support in your sidebar for answers to common seller questions. If you still need help, or want to give feedback, click Contact Support and send a support ticket; the reply shows on your ticket under My Tickets, and you get an email when your ticket is received, replied to or closed. Support may message you there to clarify, so check it from time to time; once they have, you can reply there. Once a ticket is closed it can no longer be replied to, so send a new ticket if you still need help. It is not real-time support. To appeal a rejected earnings review or withdrawal, use Dispute This Rejection on that item instead.',
                ],
                'lgu_admin' => [
                    'English' => 'Buyers and sellers send support tickets from the Help & Support contact form. Tickets from sellers in your municipality and their buyers appear in your Support Tickets tab, along with buyer tickets not about an order, which every LGU can see, and the Super Admin sees the same tickets -- whoever picks one up answers it, whatever the topic. Send Response emails your reply to the user; Internal Note is for staff only. Close the ticket when it is done; a closed ticket is final, so nobody can reply to it any more and the user sends a new ticket if they still need help.',
                ],
                'super_admin' => [
                    'English' => 'Buyers and sellers send support tickets and feedback from the Help & Support contact form. Every ticket appears in your Support Tickets tab. Seller tickets and tickets about an order are shared with the seller\'s LGU; buyer tickets not about an order are shared with every LGU, because buyers have no municipality. Whoever picks one up answers it, whatever the topic. Send Response emails your reply to the user; Internal Note is for staff only. Close the ticket when it is done; a closed ticket is final, so nobody can reply to it any more and the user sends a new ticket if they still need help.',
                ],
            ],
        ],
    ];

    /**
     * Classify a message into one of CATEGORIES.
     *
     * Matching is done in two passes, each walking TOPICS in order:
     *   1. multi-word phrase keywords (e.g. "create a listing", "how do i pay")
     *   2. single-word keywords (e.g. "listing", "pay")
     *
     * A specific phrase therefore wins over a broad single word even when the
     * single word sits in an earlier topic -- so "how do I create a listing?"
     * resolves to Listings instead of being shadowed by Marketplace's generic
     * "listing" keyword, while "how do I buy fingerlings from a listing?" still
     * resolves to Marketplace on the single-word "buy". Topics are still tried
     * in declaration order within each pass, so ties keep their original,
     * curated priority.
     *
     * Keywords match on a word boundary at their leading edge (see
     * keywordMatches), so a short keyword like "rate" matches
     * "rate"/"rating"/"rated" but never the middle of an unrelated word such as
     * "accurate" or "generate" -- eliminating the substring false positives the
     * previous str_contains matching produced.
     *
     * Topic keywords are checked before the greeting pattern, so a substantive
     * question that merely opens with "hi" (e.g. "Hi, how do I buy
     * fingerlings?") is answered on its merits rather than treated as a bare
     * greeting.
     */
    public static function classify(string $message): array
    {
        $lower = strtolower($message);

        foreach ([true, false] as $phrasePass) {
            foreach (self::TOPICS as $topic) {
                foreach ($topic['keywords'] as $keyword) {
                    if ((str_contains($keyword, ' ') === $phrasePass) && self::keywordMatches($lower, $keyword)) {
                        return ['category' => $topic['category'], 'topic' => $topic];
                    }
                }
            }
        }

        if (preg_match(self::GREETING_PATTERN, $lower)) {
            return ['category' => 'Greeting', 'topic' => null];
        }
        foreach (self::GREETING_SUBSTRINGS as $phrase) {
            if (str_contains($lower, $phrase)) {
                return ['category' => 'Greeting', 'topic' => null];
            }
        }

        // Last resort before refusing: a real fish-farming question that simply
        // isn't phrased in any curated keyword above ("my tilapia have white
        // spots", "anong pH para sa bangus?"). The TOPICS passes ran first, so
        // this can never steal a message an app topic would have claimed --
        // it only rescues ones that were previously refused outright. 'topic'
        // is null on purpose: there is no scripted answer, so GeminiService
        // answers it as an open aquaculture question instead of paraphrasing
        // a canned paragraph. See GeminiService::answerAsFarmingAdvisor().
        if (self::looksLikeFarmingQuestion($lower)) {
            return ['category' => 'Fish Care', 'topic' => null];
        }

        return ['category' => 'Unknown', 'topic' => null];
    }

    /**
     * Vocabulary that marks a message as being about raising fish rather than
     * about the app -- species, symptoms, pond/water husbandry, and the
     * Tagalog/Bisaya words farmers actually use. This is deliberately broad:
     * it runs only after every curated topic has already failed to match, and
     * the cost of a false positive (a farming answer to a vague question) is
     * far lower than the cost of the false negative it replaces (refusing a
     * legitimate question from a farmer).
     *
     * Terms are grouped only for readability; matching treats them as one flat
     * list. Anything genuinely off-topic -- politics, sports, programming,
     * homework -- shares no vocabulary with this list and still refuses.
     */
    private const FARMING_VOCABULARY = [
        // Species commonly farmed locally, including local names.
        'tilapia', 'tilapya', 'bangus', 'milkfish', 'hito', 'catfish', 'carp', 'tuna',
        'sea bass', 'seabass', 'apahap', 'shrimp', 'hipon', 'prawn', 'sugpo', 'crab',
        'alimango', 'pompano', 'grouper', 'lapu-lapu', 'eel', 'igat', 'mudfish', 'dalag',
        // Where fish are kept.
        'pond', 'ponds', 'tank', 'cage', 'hapa', 'pen', 'fishpond', 'palaisdaan',
        'nursery', 'grow-out', 'grow out', 'biofloc', 'aquaponic', 'hatchery',
        // Water chemistry and environment.
        // "DO" (dissolved oxygen) is spelled out rather than listed as a bare
        // term: as a two-letter word it matched ordinary English ("what DO you
        // think about the election?") and dragged off-topic messages into a
        // farming answer. Any abbreviation short enough to collide with a
        // common word belongs here as a phrase, not on its own.
        'ph', 'dissolved oxygen', 'do level', 'ammonia', 'nitrite', 'nitrate', 'salinity', 'brackish', 'freshwater',
        'saltwater', 'turbid', 'murky', 'aerator', 'aeration', 'oxygen', 'lime', 'apog',
        'plankton', 'temperature', 'tubig',
        // Husbandry and production.
        'stocking', 'stock density', 'spawn', 'spawning', 'breeding', 'broodstock',
        'molting', 'pellet', 'protein', 'fcr', 'feed conversion', 'biomass', 'growth rate',
        'fertilizer', 'probiotic', 'acclimate', 'acclimation', 'transport', 'pakain',
        'pagpakaon', 'alaga', 'isda',
        // Trouble -- the questions farmers most urgently need answered.
        'white spot', 'ich', 'fungus', 'fungal', 'bacterial', 'parasite', 'lesion',
        'ulcer', 'fin rot', 'bloated', 'gasping', 'floating', 'lethargic', 'not eating',
        'mortality', 'die-off', 'died', 'namatay', 'nangamatay', 'sakit', 'masakit',
        'may sakit', 'nagkasakit', 'mamatay', 'gasping for air', 'red spots', 'wounds',
        // The plain words a worried farmer actually types. Naming conditions
        // ("ich", "fin rot") only catches someone who already knows the
        // diagnosis -- the whole point of asking is usually that they don't.
        // "how to know if the fish is sick" matched nothing at all until these
        // were added, because 'sick fish' above only fires in that exact word
        // order. Keep these general; specificity belongs in the answer, not
        // the gate.
        'fish', 'sick', 'symptom', 'healthy', 'unhealthy', 'weak', 'infect', 'swim',
        'behaving', 'behavior', 'behaviour', 'spots', 'wound', 'gill', 'slimy',
        'discolor', 'discolour', 'losing scales', 'bulging eyes', 'stunted',
        'himsog', 'luya',
    ];

    /**
     * True when $lower contains any FARMING_VOCABULARY term.
     *
     * Short terms (under 4 characters, e.g. "ph", "do") are anchored at BOTH
     * edges, so "ph" matches "what ph should i use" but never "phone" or
     * "photo". Longer terms keep the leading-edge anchor used everywhere else
     * in this class, so "pond" still matches "ponds" and "spawn" matches
     * "spawning" without needing every inflection spelled out.
     */
    private static function looksLikeFarmingQuestion(string $lower): bool
    {
        foreach (self::FARMING_VOCABULARY as $term) {
            $pattern = strlen($term) < 4
                ? '/\b'.preg_quote($term, '/').'\b/'
                : '/\b'.preg_quote($term, '/').'/';

            if (preg_match($pattern, $lower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when $keyword appears in $lower at a word boundary on its leading
     * edge. This still matches trailing inflections ("rate" -> "rating",
     * "listing" -> "listings") because only the front of the keyword is
     * anchored, but it never fires on the middle of a larger word
     * ("rate" in "accurate", "pay" in "display"), which plain substring
     * matching did.
     */
    private static function keywordMatches(string $lower, string $keyword): bool
    {
        return (bool) preg_match('/\b'.preg_quote($keyword, '/').'/', $lower);
    }

    /**
     * The English fact GeminiService grounds Gemini with for a matched
     * topic -- the app's own knowledge, scoped to the asking user's role, so
     * Gemini paraphrases naturally instead of guessing how AbaiMarket works.
     */
    public static function topicContext(array $topic, string $role): string
    {
        return (self::roleEntry($topic, $role))['English'];
    }

    /**
     * Per-language offline fallback for a matched topic, used only when the
     * live Gemini call is unavailable. Falls back to the role entry's own
     * English text (never a different role's translated text) when no
     * translation was written for that role override.
     */
    public static function topicFallback(array $topic, string $role): array
    {
        $entry = self::roleEntry($topic, $role);

        return [
            'English' => $entry['English'],
            'Tagalog' => $entry['Tagalog'] ?? $entry['English'],
            'Bisaya' => $entry['Bisaya'] ?? $entry['English'],
        ];
    }

    private static function roleEntry(array $topic, string $role): array
    {
        return $topic['roles'][$role] ?? $topic;
    }

    /**
     * What the greeting says the assistant can help with, per role and
     * language. The 'buyer'/'English' entry is byte-identical to the
     * assistant's original (pre-role-aware) greeting text.
     */
    private const GREETING_CAPABILITIES = [
        'buyer' => [
            'English' => 'buying fingerlings, contacting sellers, orders, payments, delivery, reviews, or fish farming basics like species, water quality, and feeding',
            'Tagalog' => 'pagbili ng fingerlings, pakikipag-ugnayan sa mga seller, orders, bayad, delivery, reviews, o mga batayan ng fish farming tulad ng species, kalidad ng tubig, at pagpapakain',
            'Bisaya' => 'pagpalit og fingerlings, pagkontak sa mga seller, orders, bayad, delivery, reviews, o mga sukaranan sa fish farming sama sa species, kalidad sa tubig, ug pagpakaon',
        ],
        // Sellers are the fish farmers themselves, so fish care belongs in
        // what they're told the assistant can do -- it was previously offered
        // to buyers only, which hid it from the people most likely to need it.
        'seller' => [
            'English' => 'your listings, orders, wallet, seller earnings, withdrawals, reviews, business recommendations, and fish farming -- water quality, feeding, disease, and harvesting',
            'Tagalog' => 'iyong mga listing, orders, wallet, seller earnings, withdrawals, reviews, mga business recommendation, at fish farming -- kalidad ng tubig, pagpapakain, sakit, at pag-harvest',
            'Bisaya' => 'imong mga listing, orders, wallet, seller earnings, withdrawals, reviews, mga business recommendation, ug fish farming -- kalidad sa tubig, pagpakaon, sakit, ug pag-harvest',
        ],
        'lgu_admin' => [
            'English' => 'pending approvals, seller verification, seller earnings, reports, and municipality statistics',
            'Tagalog' => 'mga pending approval, pag-verify ng seller, seller earnings, reports, at estadistika ng munisipyo',
            'Bisaya' => 'mga pending approval, pag-verify sa seller, seller earnings, reports, ug estadistika sa munisipyo',
        ],
        'super_admin' => [
            'English' => 'platform-wide statistics, listings, payouts, reports, and municipality comparisons',
            'Tagalog' => 'estadistika ng buong platform, listings, payouts, reports, at paghahambing ng munisipyo',
            'Bisaya' => 'estadistika sa tibuok platform, listings, payouts, reports, ug pagtandi sa munisipyo',
        ],
    ];

    /**
     * The offline fallback for an open fish-farming question -- one recognized
     * by looksLikeFarmingQuestion() rather than by a curated topic, so there is
     * no scripted answer to fall back to. Reuses the general Fish Care topic's
     * own text (the "ask me something specific" prompt) rather than duplicating
     * it, so the two can never drift apart.
     */
    public static function generalFishCareFallback(): array
    {
        foreach (self::TOPICS as $topic) {
            if (in_array('fish farming', $topic['keywords'], true)) {
                return [
                    'English' => $topic['English'],
                    'Tagalog' => $topic['Tagalog'],
                    'Bisaya' => $topic['Bisaya'],
                ];
            }
        }

        // Unreachable while that topic exists; kept so a future edit to TOPICS
        // degrades to a sensible answer instead of an undefined-index error.
        return [
            'English' => 'I can help with fish-farming questions such as water quality, feeding, disease, and harvesting. Please ask a specific question.',
            'Tagalog' => 'Makakatulong ako sa mga tanong tungkol sa fish farming tulad ng kalidad ng tubig, pagpapakain, sakit, at pag-harvest. Magtanong nang tiyak.',
            'Bisaya' => 'Makatabang ko sa mga pangutana bahin sa fish farming sama sa kalidad sa tubig, pagpakaon, sakit, ug pag-harvest. Pangutana og espisipiko.',
        ];
    }

    public static function greetingResponse(string $language, string $role = 'buyer'): string
    {
        $roleCapabilities = self::GREETING_CAPABILITIES[$role] ?? self::GREETING_CAPABILITIES['buyer'];
        $capabilities = $roleCapabilities[$language] ?? $roleCapabilities['English'];

        $responses = [
            'English' => "Hello! I'm the AbaiMarket assistant. Ask me about {$capabilities}.",
            'Tagalog' => "Kumusta! Ako ang AbaiMarket assistant. Magtanong tungkol sa {$capabilities}.",
            'Bisaya' => "Kumusta! Ako ang AbaiMarket assistant. Pangutan-a ko bahin sa {$capabilities}.",
        ];

        return $responses[$language] ?? $responses['English'];
    }

    /**
     * Polite refusal for messages classified Unknown -- used instead of
     * forwarding the prompt to the live model or fabricating an answer, so
     * off-topic questions (trivia, politics, sports, programming, homework,
     * etc.) never get a made-up response.
     */
    public static function offTopicResponse(string $language): string
    {
        $responses = [
            'English' => "I'm your AbaiMarket AI Assistant, dedicated to the AbaiMarket fisheries marketplace. I can help you with buying and selling fingerlings, fish farming, marketplace features, listings, orders, wallets, deliveries, messaging, reviews, analytics, and other features of this Fisheries Marketplace system -- I can't answer unrelated general knowledge questions.",
            'Tagalog' => 'Ako ang iyong AbaiMarket AI Assistant, nakatuon sa AbaiMarket fisheries marketplace. Matutulungan kita sa pagbili at pagbebenta ng fingerlings, fish farming, mga feature ng marketplace, listings, orders, wallets, delivery, messaging, reviews, analytics, at iba pang feature ng Fisheries Marketplace system na ito -- hindi ako makakasagot ng mga hindi kaugnay na pangkalahatang tanong.',
            'Bisaya' => 'Ako ang imong AbaiMarket AI Assistant, nakatuon sa AbaiMarket fisheries marketplace. Makatabang ko nimo sa pagpalit ug pagbaligya og fingerlings, fish farming, mga feature sa marketplace, listings, orders, wallets, delivery, messaging, reviews, analytics, ug uban pang feature niining Fisheries Marketplace system -- dili ko makatubag sa dili kalabot nga kinatibuk-ang mga pangutana.',
        ];

        return $responses[$language] ?? $responses['English'];
    }
}
