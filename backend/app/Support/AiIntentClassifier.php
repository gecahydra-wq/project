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
            'English' => 'FishMarket does not currently support automated refunds through the app. If there\'s a problem with an order, message the seller directly first -- most issues are resolved that way. If it involves a rule violation, your municipality\'s LGU Admin can review the listing and take action.',
            'Tagalog' => 'Wala pang automated refund ang FishMarket sa app. Kung may problema sa order, i-message muna nang direkta ang seller -- karamihan ng isyu ay naaayos sa ganitong paraan. Kung may paglabag sa alituntunin, maaaring suriin ito ng LGU Admin ng iyong munisipyo.',
            'Bisaya' => 'Wala pay automated refund ang FishMarket sa app. Kung naay problema sa order, i-message una direkta ang seller -- kadaghanan sa isyu masulbad ana nga paagi. Kung naay paglapas sa lagda, mahimong susihon kini sa LGU Admin sa imong munisipyo.',
        ],
        [
            'category' => 'Delivery',
            'keywords' => ['delivery', 'shipping', 'deliver', 'arrive', 'when will my order', 'order arrive', 'eta'],
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
            'keywords' => ['review', 'rate', 'rating', 'leave a review'],
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
            'keywords' => ['order status', 'my order', 'orders', 'track'],
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
                    'English' => 'You verify sellers in your municipality from the Sellers tab -- review their hatchery details and mark them Verified, or Suspend a seller who violates marketplace rules (this immediately revokes their login access).',
                ],
            ],
        ],
        [
            'category' => 'Fish Care',
            'keywords' => ['beginner', 'good species', 'what species', 'which species', 'species available'],
            'English' => 'For beginners, Tilapia is a great starting point -- hardy, tolerates imperfect water, grows fast (about 6 months to harvest), and has strong market demand. FishMarket also lists Bangus, Grouper, Catfish, Sea Bass, and Carp fingerlings; use the Species filter on Browse to compare what local sellers currently have in stock.',
            'Tagalog' => 'Para sa mga baguhan, magandang simulan ang Tilapia -- matibay, kayang mag-adjust sa di-perpektong tubig, mabilis lumaki (mga 6 buwan hanggang harvest), at may mataas na demand sa merkado. May Bangus, Grouper, Catfish, Sea Bass, at Carp fingerlings din sa FishMarket -- gamitin ang Species filter sa Browse para makita kung ano ang available ngayon.',
            'Bisaya' => 'Para sa mga baguhan, maayo ang Tilapia isugod -- lig-on siya, ka-adjust sa dili perpekto nga tubig, paspas motubo (mga 6 ka bulan hangtod sa harvest), ug taas ang demand sa merkado. Naa say Bangus, Grouper, Catfish, Sea Bass, ug Carp fingerlings sa FishMarket -- gamita ang Species filter sa Browse para makita unsay naa karon.',
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
            'keywords' => ['withdraw', 'withdrawal', 'payout', 'payouts', 'cash out', 'how do i get paid'],
            'English' => 'Withdrawals let a Seller cash out their Available Balance via GCash, Maya, or bank transfer. The Seller requests a withdrawal, and the Super Admin reviews, approves, and marks it paid before the amount moves from Available Balance to Withdrawn Amount.',
            'Tagalog' => 'Sa Withdrawals, maaaring i-cash out ng Seller ang kanilang Available Balance via GCash, Maya, o bank transfer. Nag-rerequest ang Seller ng withdrawal, at ang Super Admin ang sumusuri, umaaprub, at nagmamarka nito bilang bayad bago ito lumipat mula Available Balance patungong Withdrawn Amount.',
            'Bisaya' => 'Ang Withdrawals nagtugot sa Seller nga i-cash out ang ilang Available Balance pinaagi sa GCash, Maya, o bank transfer. Mo-request ang Seller og withdrawal, ug ang Super Admin ang mo-review, mo-aprubar, ug mag-marka niini nga bayad ayha kini mobalhin gikan sa Available Balance ngadto sa Withdrawn Amount.',
            'roles' => [
                'seller' => [
                    'English' => 'To withdraw: open your Wallet and request a withdrawal for up to your Available Balance via GCash, Maya, or bank transfer. The Super Admin reviews and approves it, then marks it paid -- at that point it moves from Available Balance to Withdrawn Amount.',
                ],
                'lgu_admin' => [
                    'English' => 'Withdrawals are handled platform-wide by the Super Admin, after a seller\'s earnings have been released through your Seller Earnings approval. LGU admins don\'t process withdrawal requests directly.',
                ],
                'super_admin' => [
                    'English' => 'Withdrawal requests appear in Payout Management. Review each one, Approve it once verified, then Mark Paid after you\'ve sent the funds -- or Reject with a reason if it can\'t be honored. Approving or marking paid notifies the seller automatically.',
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
            'English' => 'FishMarket operates across several municipalities, each supervised by its own LGU Admin who approves the sellers and listings registered there. Ask me for counts (e.g. "how many sellers are in Cordova?") for live numbers.',
            'Tagalog' => 'Ang FishMarket ay gumagana sa ilang munisipyo, bawat isa ay sinusubaybayan ng sariling LGU Admin na siyang nag-aaprubang ng mga seller at listing na naka-rehistro doon. Magtanong ng bilang (hal. "ilan ang seller sa Cordova?") para sa live na numero.',
            'Bisaya' => 'Ang FishMarket naglihok sa daghang munisipyo, matag usa gibantayan sa kaugalingong LGU Admin nga nag-aprubar sa mga seller ug listing nga narehistro didto. Pangutana og count (pananglitan, "pila ka seller sa Cordova?") para sa live nga numero.',
        ],
    ];

    /**
     * Classify a message into one of CATEGORIES. Topic keywords are checked
     * before the greeting pattern, so a substantive question that happens to
     * open with "hi" (e.g. "Hi, how do I buy fingerlings?") is answered on
     * its merits rather than treated as a bare greeting.
     */
    public static function classify(string $message): array
    {
        $lower = strtolower($message);

        foreach (self::TOPICS as $topic) {
            foreach ($topic['keywords'] as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return ['category' => $topic['category'], 'topic' => $topic];
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

        return ['category' => 'Unknown', 'topic' => null];
    }

    /**
     * The English fact GeminiService grounds Gemini with for a matched
     * topic -- the app's own knowledge, scoped to the asking user's role, so
     * Gemini paraphrases naturally instead of guessing how FishMarket works.
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
        'seller' => [
            'English' => 'your listings, orders, wallet, seller earnings, withdrawals, reviews, and business recommendations',
            'Tagalog' => 'iyong mga listing, orders, wallet, seller earnings, withdrawals, reviews, at mga business recommendation',
            'Bisaya' => 'imong mga listing, orders, wallet, seller earnings, withdrawals, reviews, ug mga business recommendation',
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

    public static function greetingResponse(string $language, string $role = 'buyer'): string
    {
        $roleCapabilities = self::GREETING_CAPABILITIES[$role] ?? self::GREETING_CAPABILITIES['buyer'];
        $capabilities = $roleCapabilities[$language] ?? $roleCapabilities['English'];

        $responses = [
            'English' => "Hello! I'm the FishMarket assistant. Ask me about {$capabilities}.",
            'Tagalog' => "Kumusta! Ako ang FishMarket assistant. Magtanong tungkol sa {$capabilities}.",
            'Bisaya' => "Kumusta! Ako ang FishMarket assistant. Pangutan-a ko bahin sa {$capabilities}.",
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
            'English' => "I'm your FishMarket AI Assistant, dedicated to the FishMarket fisheries marketplace. I can help you with buying and selling fingerlings, fish farming, marketplace features, listings, orders, wallets, deliveries, messaging, reviews, analytics, and other features of this Fisheries Marketplace system -- I can't answer unrelated general knowledge questions.",
            'Tagalog' => 'Ako ang iyong FishMarket AI Assistant, nakatuon sa FishMarket fisheries marketplace. Matutulungan kita sa pagbili at pagbebenta ng fingerlings, fish farming, mga feature ng marketplace, listings, orders, wallets, delivery, messaging, reviews, analytics, at iba pang feature ng Fisheries Marketplace system na ito -- hindi ako makakasagot ng mga hindi kaugnay na pangkalahatang tanong.',
            'Bisaya' => 'Ako ang imong FishMarket AI Assistant, nakatuon sa FishMarket fisheries marketplace. Makatabang ko nimo sa pagpalit ug pagbaligya og fingerlings, fish farming, mga feature sa marketplace, listings, orders, wallets, delivery, messaging, reviews, analytics, ug uban pang feature niining Fisheries Marketplace system -- dili ko makatubag sa dili kalabot nga kinatibuk-ang mga pangutana.',
        ];

        return $responses[$language] ?? $responses['English'];
    }
}
