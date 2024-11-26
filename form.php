<?php

namespace App\Form;

use App\Services\ServiceLomaco;
use App\Controller\LomacoController;
use Symfony\Component\Form\FormEvent;
use App\Services\ServiceErrorAdminAPI;

use Symfony\Component\Form\FormEvents;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use Symfony\Component\Validator\Constraints as Assert;

class SynchApiType extends AbstractType
{
    private $agence = [];
    private $magasin;
    private $serviceLomaco;
    private  $session;
    private $doctrine;
    private $errorAdminApi;
    private $entityManager;
    private $addOrthop;



    public function __construct(ServiceLomaco $serviceLomaco, RequestStack $requestStack, ManagerRegistry $doctrine, ServiceErrorAdminAPI $errorAdminApi, EntityManagerInterface $entityManager)
    {
        $this->serviceLomaco = $serviceLomaco;
        $this->session  = $requestStack;
        $this->doctrine  = $doctrine;
        $this->errorAdminApi = $errorAdminApi;
        $this->entityManager = $entityManager;
    }

    private function getOptionsSelonIdAgence($idAgence)
    {
        if (!is_null($idAgence)) {

        $id = array_search($idAgence, $this->agence);
        $lomaco = new LomacoController($this->serviceLomaco, $this->session, $this->doctrine, $this->errorAdminApi, $this->entityManager);
        $depot = $lomaco->searchDepot($id, $this->magasin);

        if (!is_null($depot)) {
            return $depot;
        }

        }
    }

    /**
     * Fonction de validation personnalisée.
     *
     * @param mixed $data Les données du formulaire
     * @param ExecutionContextInterface $context Le contexte de validation
     */
    // c'est un pre submit par defaut
    public function validate($data, ExecutionContextInterface $context)
    {
        // Récupération du formulaire racine
        $form = $context->getRoot();

        if (isset($this->addOrthop) && $this->addOrthop === false) {
           return;
        }

        // Récupération des données des champs spécifiques
        $codeParticulier = $form->get('code_type_particulier')->getData();
        $codeProfessionnel = $form->get('code_type_professionnel')->getData();


        // Vérification si les deux champs sont vides
        if (empty($codeParticulier) || empty($codeProfessionnel)) {
            // Ajout d'une violation (message d'erreur)
            $context->buildViolation('Vous devez remplir au moins l\'un des deux champs : Code type particulier ou Code type professionnel.')
                ->atPath('code_type_particulier') // Vous pouvez choisir un autre chemin ou les deux
                ->addViolation();
        }
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {

        $this->addOrthop = $options['addOrthop'];

        $this->agence = $options['agence'];
        $this->magasin = $options['magasin'];

        $builder

            // partie Lomaco
            ->add('nomDuChamp', ChoiceType::class, [
                'choices' => $options['agence'],
                'choice_label' => function ($choice) {
                    return $choice; // Utilisez la valeur du tableau pour le label (nom de l'agence)
                },
                'choice_attr' => function ($choice, string $key, mixed $value) {
                    // Ici, vous pouvez retourner un tableau d'attributs basés sur $choice, $key ou $value
                    return ['data-id-agence' => $key];
                },
                'placeholder' =>    'Séléctionner votre agence',
                'label' => 'Agences',
                'required' => true,
            ])

            
            ->add('champDynamique', ChoiceType::class, [
                // uses the User.username property as the visible option string
                'choice_label' => 'libelle',
                'placeholder' =>    'Type de problème',
                'label' => 'Type Problème',
                'required' => true,
            ])
            // partie Lomaco

            // partie Orthop
            ->add('code_etablissement',TextType::class,[
                'label' => 'Code établissement',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'placeholder' => 'Ajouter votre code établissement',
                ],
            ])
            
            ->add('code_type_particulier',TextType::class,[
                'label' => 'Code type particulier',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ajouter votre code code type',
                ],
            ])
            
            ->add('code_type_professionnel',TextType::class,[
                'label' => 'Code type professionnel',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'Ajouter votre code code type',
                ],
            ])
            

            ->add('submit', SubmitType::class, [
                'label' => '<span class="btnSyncOrthop spinner-border spinner-border-sm hiddenSpinner" role="status" aria-hidden="true"></span><small>' . $options['api'] . '</small> <br> Commencer la synchronisation',
                'label_html' => true,
                'attr' => [
                    'class' => 'btn btn-primary spinnerAuth',
                ],
            ]);

        $formModifier = function (FormInterface $form, $idAgence) {
            $optionsChampDynamique = $this->getOptionsSelonIdAgence($idAgence);

            $form->add('champDynamique', ChoiceType::class, [
                'choices' => $optionsChampDynamique,
                'placeholder' =>    'Séléctionner votre dépot',
                'label' => 'Dépot',
            ]);
        };

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($formModifier): void {
                $data = $event->getData();

                // Vérifiez si $data est un objet et possède la méthode getNomDuChamp
                if ($data && method_exists($data, 'getNomDuChamp')) {
                    $idAgence = $data->getNomDuChamp();
                    $formModifier($event->getForm(), $idAgence);
                } else {
                    // Traitez le cas où $data est null ou ne possède pas getNomDuChamp
                    // Par exemple, initialiser champDynamique avec des valeurs par défaut
                    $formModifier($event->getForm(), null);
                }
            }
        );

        $builder->get('nomDuChamp')->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event) use ($formModifier): void {
                // Récupération de la valeur soumise pour nomDuChamp
                $idAgence = $event->getForm()->getData();
                $formModifier($event->getForm()->getParent(), $idAgence);
            }
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'api' => '',
            'addOrthop' => '',
            'agence' => [],
            'magasin' => null, 
            // Ajout de la contrainte Callback pour la validation personnalisée
            'constraints' => [
                new Assert\Callback([$this, 'validate']),
            ],
        ]);
    }
}
